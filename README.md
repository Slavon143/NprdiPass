# NordiPass

NordiPass is a Laravel application. The repository currently contains the R0 Foundation Stage 1 bootstrap, Stage 2 core database, Stage 3 tenancy context, Stage 4 authorization foundation, Stage 5 company-facing UI, and the Stage 6 company invitation lifecycle. Audit logging, API tokens, and later product modules remain intentionally deferred.

## Requirements

- PHP 8.4 or newer with `fileinfo`, `pdo_mysql`, and `pdo_sqlite` for the test suite
- Composer 2
- Node.js 22 or newer with npm
- MySQL 8 or a modern MariaDB release

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the local MySQL database:

```sql
CREATE DATABASE nordipass CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Set the local MySQL username and password in `.env`. Never commit that file.

Install frontend dependencies, build the assets, and initialize the database:

```bash
npm install
npm run build
php artisan migrate --seed
```

Start the application and the Vite development server in separate terminals:

```bash
php artisan serve
npm run dev
php artisan queue:work
```

The application is available at `http://localhost:8000`. Public registration is disabled; new accounts are created only through a valid company invitation. Local seed users use the password `password`.

## Tenancy architecture

NordiPass uses a shared database and shared schema. `Company` is the tenant, and company roles belong to the `company_user` membership rather than the user record.

The current company is stored server-side in the session as an internal numeric company ID. The session value is never trusted by itself: every read verifies the authenticated user, the company record, soft-delete state, and a current database membership. A stale or foreign selection is removed from the session. A single active company is selected automatically; users with multiple active companies must choose one explicitly.

Tenant routes use this middleware order:

```text
auth
verified
company.resolve
company.selected
company.member
company.active
```

Company switching uses `POST /companies/{company:uuid}/switch`. UUID route binding identifies the requested company, while an explicit membership query provides authorization. Request body fields such as `company_id`, `company_uuid`, and `tenant_id` must never establish tenant context.

Only active companies can be selected or used for tenant work. Suspended companies return `423 Locked` for JSON tenant requests and use an informational web page. Archived companies are excluded from selection and tenant actions return `403 Forbidden` when an archived selection is encountered.

NordiPass intentionally does not use a global tenant scope. Future tenant records must always be queried explicitly through the current company relationship or with both `company_id` and the record identifier:

```php
$currentCompany
    ->documents()
    ->where('uuid', $documentUuid)
    ->firstOrFail();

Document::query()
    ->where('company_id', $currentCompany->id)
    ->where('uuid', $documentUuid)
    ->firstOrFail();
```

The example documents are query rules only; document models are not part of the current stage.

Queue jobs must receive a scalar `company_id` or company UUID and load the company explicitly inside `handle()`. They must not serialize the current-company service or use a web session. Future tenant-aware Artisan commands must require an explicit `--company=` option or deliberately iterate over all companies. Long-running workers must not store tenant models in static properties or mutable global singletons.

## Authorization architecture

Authorization has two deliberately separate levels:

- Spatie Permission stores only platform roles and permissions. The current platform role is `super_admin`, with explicit `platform.*` permissions.
- Company roles (`owner`, `admin`, `editor`, and `viewer`) remain on the `company_user` membership. They are never assigned as global Spatie roles.

A platform `super_admin` does not automatically become a company member and has no global Gate bypass. Tenant routes still require a selected company and a fresh membership. Future platform administration will use a separate platform context.

Company permissions are fixed in `CompanyPermission` and evaluated centrally by `CompanyPermissionMatrix`:

| Role | Company permissions |
| --- | --- |
| owner | All company permissions |
| admin | All matrix permissions, subject to owner-protection rules in actions |
| editor | `company.view`, `members.view` |
| viewer | `company.view` |

Named Gates delegate to `CompanyAuthorizer`, which verifies an active user, the exact `CurrentCompany`, and a freshly queried membership. Policies cover companies, company memberships, and company invitations. Audit and company API-token models do not exist yet, so their abilities are available as Gates without fake models or policies.

Member role changes and removals go through dedicated actions. Both actions run inside a database transaction, lock the company row to serialize competing ownership changes, and lock owner membership rows in a stable order. An active company cannot lose its last owner. Admins cannot assign, change, downgrade, or remove an owner, and generic member removal cannot be used as a self-leave flow.

Tenant resources must be loaded within `CurrentCompany` before policy authorization. Do not load a membership or future tenant entity globally by numeric ID and rely on a policy to hide its existence.

Security-critical authorization tests can be run against the dedicated MySQL database `nordipass_testing`:

```sql
CREATE DATABASE nordipass_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php vendor/bin/pest --configuration=phpunit.mysql.xml
```

The MySQL test configuration reuses local connection credentials but overrides the database name. The test base class refuses any MySQL database whose name does not end in `_testing`.

## Company UI

Authenticated tenant pages use the Stage 3 middleware chain and Stage 4 Policies and Actions:

| Page | Route | Access |
| --- | --- | --- |
| Dashboard | `GET /dashboard` | All active company members |
| Company settings | `GET /settings/company` | All company viewers; editor/viewer receive a read-only view |
| Update company | `PATCH /settings/company` | Owner and admin |
| Members | `GET /settings/members` | Owner, admin, and editor |
| Update member role | `PATCH /settings/members/{membership}/role` | Owner/admin subject to owner-safety rules |
| Remove member | `DELETE /settings/members/{membership}` | Owner/admin subject to owner-safety rules |

The dashboard shows only real foundation data: company identity and status, the current membership role, member count, and company creation date. It does not display placeholder product, document, billing, scan, or usage metrics.

Company settings allow only `name`, `legal_name`, `organization_number`, `country_code`, and `billing_email`. The request normalizes country code to uppercase and billing email to lowercase. Status, UUID, settings JSON, ownership, and internal identifiers are not editable.

The members page is tenant-scoped, eager loads users, sorts by the explicit owner/admin/editor/viewer priority, and paginates at 25 memberships. Role changes and removals first resolve the membership through `CurrentCompany`, so a foreign membership identifier returns 404 before authorization. Controllers call the Stage 4 transactional actions; they never update or delete memberships directly.

At least one owner is required. The UI hides the sole-owner downgrade control and generic self-removal, while the database transaction and row locks remain the authoritative protection against stale pages and concurrent requests.

## Invitation flow

Owner and admin memberships can manage invitations from `GET /settings/members`. Owners may invite `owner`, `admin`, `editor`, or `viewer`; admins may invite only `admin`, `editor`, or `viewer`. Editor and viewer memberships cannot create, resend, cancel, or list invitation history. All management lookups start from `CurrentCompany`, so an invitation UUID from another tenant returns 404.

Creating an invitation normalizes the address with `trim` and `mb_strtolower`, verifies that it is not already a current company member, and locks the company plus matching pending invitation rows inside a transaction. A repeated invite or resend cancels the previous pending record and creates a new UUID, token, hash, and expiry. Only the SHA-256 token hash is stored in `company_invitations`; the URL-safe raw token is generated from 48 cryptographically secure random bytes and is returned only to the notification boundary. Cancellation sets `cancelled_at` and preserves history.

Invitation links use `GET /invitations/{uuid}?token=...`. UUID identifies the record but is never accepted as proof of access. Token verification hashes the supplied value and uses `hash_equals`. Valid links show safe accepted, cancelled, expired, account-registration, account-login, or email-mismatch states. Invitation responses set `Cache-Control: no-store, private`, `Pragma: no-cache`, and `Referrer-Policy: no-referrer`; their dedicated Blade layout loads only same-origin Vite assets.

Existing users sign in and accept with `POST /invitations/{uuid}/accept`. The action locks the company, invitation, and user rows, verifies the token and normalized email again, blocks suspended users and duplicate memberships, creates the membership with role-derived `is_owner`, records `joined_at` and `accepted_at`, and selects the accepted company in the session. An existing `invited` user becomes active after successful acceptance. Public Breeze registration is disabled. A guest without an account uses the invitation-only registration form; the email is fixed by the invitation, considered verified by possession of the emailed secret link, and the user, membership, and acceptance are committed atomically.

`CompanyInvitationNotification` implements `ShouldQueue` and is marked `afterCommit`. Controllers dispatch it only after the invitation action transaction has returned. Queue workers receive explicit invitation data and never use the web `CurrentCompany` service. The queue payload necessarily contains the secret acceptance URL, so queue storage and `failed_jobs` are sensitive infrastructure: restrict access, use encryption-at-rest where available, and prune failed jobs according to operations policy. The invitation-specific mailer defaults to the in-memory `array` transport to prevent secret URLs entering application logs. Configure `INVITATION_MAILER=smtp` for a trusted SMTP service or a local Mailpit instance; do not use the `log` mailer for real invitation links.

Invitation expiry defaults to 72 hours. History retention defaults to 180 days. The daily scheduler runs:

```bash
php artisan nordipass:prune-invitations
```

The command deletes only old accepted, cancelled, or expired records. It never removes a valid pending invitation, and `--dry-run` reports the count without changing data.

Invitation rate limits are intentionally separate: create/resend management is limited to 10 requests per minute per user and company, token-page verification to 20 requests per minute per IP, and accept/registration to 10 requests per minute per IP. Invitation redirects are fixed internal routes; arbitrary return URLs are not accepted.

Local test users all use the password `password`:

- `owner@nordipass.local`
- `admin@nordipass.local`
- `editor@nordipass.local`
- `viewer@nordipass.local`
- `multi@nordipass.local`

The multi-company user opens the company switcher and can switch between the two seeded active companies. `superadmin@nordipass.local` remains a platform user without an implicit company membership.

## Quality checks

```bash
composer test
composer lint
composer analyse
composer quality
```

The individual equivalent commands are:

```bash
php artisan test
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

On Windows, Composer scripts are the portable way to run tools from `vendor/bin`.

## Stack

- Laravel 13 with Blade
- Laravel Breeze authentication
- Laravel Sanctum
- Tailwind CSS and Alpine.js
- Vite
- Pest
- Laravel Pint
- PHPStan with Larastan
