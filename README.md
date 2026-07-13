# NordiPass

NordiPass is a Laravel application. The repository currently contains R0 Foundation Stages 1–8: bootstrap, core database, tenancy, authorization, company UI, invitations, tenant-aware audit, and the REST API foundation. Infrastructure hardening and later product modules remain intentionally deferred.

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

## Environment Configuration

NordiPass supports four environments: `local`, `testing`, `staging`, and `production`. See [`docs/infrastructure/ENVIRONMENTS.md`](docs/infrastructure/ENVIRONMENTS.md) for the full environment table.

### Local

The local environment uses `mysql` with `database` cache, queue, and session drivers. No external services (Redis, SMTP) are required. Copy `.env.example` to `.env`, set database credentials, and run:

```bash
php artisan key:generate
php artisan migrate --seed
```

Mail defaults to `log`. For Mailpit, set `MAIL_MAILER=smtp` with `MAIL_HOST=127.0.0.1` and `MAIL_PORT=1025`.

### Testing

The default test suite uses SQLite `:memory:` (`phpunit.xml`). MySQL integration tests use a dedicated `nordipass_testing` database (`phpunit.mysql.xml`).

### Staging and Production

Production must use Redis for cache, queue, and sessions. Set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and configure trusted SMTP. See `docs/infrastructure/ENVIRONMENTS.md`.

### APP_KEY

`APP_KEY` is generated **once** per environment with `php artisan key:generate`. Never regenerate it on each deployment — doing so invalidates sessions, encrypted cookies, and encrypted data.

### Secrets

`.env` must never be committed. On Linux production, restrict permissions to `chmod 600` and ensure the document root points to `public/`.

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

Named Gates delegate to `CompanyAuthorizer`, which verifies an active user, the exact `CurrentCompany`, and a freshly queried membership. Policies cover companies, company memberships, company invitations, and tenant audit history. Company API-token models do not exist yet, so their abilities remain available as Gates without fake models or policies.

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

## Audit architecture

NordiPass uses `spatie/laravel-activitylog` with the custom immutable `AuditLog` model and explicit security events. It does not auto-log every model change. Tenant rows use `log_name=tenant` and a validated `company_id`; platform rows use `log_name=platform` and `company_id=null`. The tenant page always queries both the current internal company ID and the tenant log name, so another company's rows and platform activity cannot appear through filters or pagination. A physically deleted company sets its audit foreign key to `null` while safe company UUID/name snapshots preserve historical context.

The current application flows record:

- successful login, failed login, and logout;
- company settings changes and company switches;
- member role changes and removals;
- invitation creation, resend, cancellation, and acceptance;
- denied company-switch attempts as platform security events.

The event enum also defines `company.created` and platform role/action codes for future explicit application flows. There is no user-facing company creation or platform-role management flow in Stage 7, so seeders and factories do not create misleading audit events.

Company settings, membership, and invitation actions write the audit row inside the same local database transaction after the successful state change and before commit. If the audit insert fails, the business mutation rolls back. Invitation email remains an `afterCommit` external side effect. Login/logout events are synchronous Laravel authentication listeners because those operations do not share a business database transaction.

Every HTTP request receives a validated or generated `X-Request-ID`. Accepted IDs are 1–100 letters, digits, dots, underscores, or hyphens. The ID is returned in the response, added to Laravel log context for the request, and stored on audit rows. IP addresses come from Laravel's trusted-proxy-aware `Request::ip()` and can be disabled with `AUDIT_STORE_IP=false`. User agents have control characters removed and are capped at `AUDIT_USER_AGENT_MAX_LENGTH` (and the 500-character database limit). Configure trusted proxies for the deployment; the application never parses `X-Forwarded-For` itself.

Audit properties are allowlisted at each action boundary and recursively sanitized. Passwords, raw or hashed tokens, authorization/cookie/session values, secret URLs, whole request payloads, settings JSON, notification payloads, and message/document/payment contents are not stored. Properties contain only small snapshots and action-specific diffs. The UI renders human labels and safe summaries rather than raw JSON or PHP model class names.

Only current active-company owners and admins have `audit.view`; editors, viewers, and platform super administrators without a membership do not. `GET /audit` supports tenant-scoped event, actor, and date filters, limits ranges to 366 days, and paginates 50 events per page. Individual editing, deletion, detail routes, and export are intentionally absent.

Tenant audit retention defaults to 365 days. The scheduler runs the chunked command daily:

```bash
php artisan nordipass:prune-audit-logs
```

Use `--dry-run`, `--days=`, and `--company=<company-uuid>` for controlled operations. Company-scoped pruning never touches another company. Unscoped pruning targets only rows historically marked `log_name=tenant`, including orphaned tenant history after a physical company deletion; platform rows are preserved for a separate future platform retention policy.

## API Foundation

Stage 8 adds `/api/v1` with Laravel Sanctum. Foundation routes are public `GET /health`, `GET /me` and `GET /company` with `company.read`, and paginated `GET /company/members` with `members.read`. Product, Document, QR, DPP, integrations, billing, and other business APIs are absent.

Every normal token belongs to one User + Company pair. Its `company_id` is the only tenant source for bearer requests; the web session and request `company_id`/`company_uuid` cannot override it. Middleware checks the token, active user, active company, fresh membership, and endpoint ability. Null-company, expired, revoked, inactive, and membership-less tokens fail immediately. No global tenant scope is used.

Owners and admins manage tokens at `/settings/api-tokens`; editors and viewers cannot. Only `company.read` and `members.read` are issuable, with no wildcard. The raw secret appears once in a no-store/no-referrer response and only its Sanctum SHA-256 hash is stored. Create/revoke writes secret-free audit events. Membership removal revokes only that user's tokens for that company inside the existing transaction; a role downgrade keeps ability-limited tokens while membership remains.

Expiration defaults to 90 days, has a 365-day maximum, and disables non-expiring tokens by default. API responses share `data` / `meta` / `error`, stable codes, request IDs, UUID-only resources, no-store/nosniff headers, and pagination capped at 100. Limits are 60/min per IP for health, 120/min per token, 10/min per user/company for creation, and 30/min for revoke. CORS uses explicit `API_ALLOWED_ORIGINS` without credentials.

Sanctum updates `last_used_at` after each successful bearer authentication, which means one database write per accepted API request. This is intentional for R0 visibility and should be revisited if future business APIs reach high request volume.

The daily scheduler runs `php artisan nordipass:prune-api-tokens`; use `--dry-run` and `--days=`. See [`docs/API.md`](docs/API.md) and [`docs/openapi.yaml`](docs/openapi.yaml).

## CI and Deployment

NordiPass uses GitHub Actions for CI, release build, and production deployment. See [`docs/infrastructure/CI_AND_DEPLOYMENT.md`](docs/infrastructure/CI_AND_DEPLOYMENT.md) and [`docs/infrastructure/RELEASE_CHECKLIST.md`](docs/infrastructure/RELEASE_CHECKLIST.md).

## Backup and Disaster Recovery

NordiPass provides built-in backup and restore commands using native MySQL `mysqldump`. Backups include the database and application-managed files. See [`docs/infrastructure/BACKUP_AND_RESTORE.md`](docs/infrastructure/BACKUP_AND_RESTORE.md) and [`docs/infrastructure/DISASTER_RECOVERY.md`](docs/infrastructure/DISASTER_RECOVERY.md).

## Health, Logging and Security

NordiPass provides a liveness endpoint (`GET /up`), a readiness endpoint (`GET /ready`), structured JSON logging for production, X-Request-ID correlation, security headers, and configurable HSTS, trusted proxies, and trusted hosts.

See [`docs/infrastructure/HEALTH_LOGGING_SECURITY.md`](docs/infrastructure/HEALTH_LOGGING_SECURITY.md) for full documentation.

## Queues and Scheduler

NordiPass uses three queues: `mail` (invitation notifications), `maintenance` (pruning and cleanup), and `default` (other background jobs). The local environment uses the database driver; production uses Redis.

```bash
# Local worker
php artisan queue:work --queue=mail,maintenance,default --sleep=1 --tries=3 --timeout=300

# Local scheduler
php artisan schedule:work
```

See [`docs/infrastructure/QUEUES_AND_SCHEDULER.md`](docs/infrastructure/QUEUES_AND_SCHEDULER.md) for full worker configuration, Supervisor, cron, and Redis readiness.

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
- Spatie Laravel Activitylog
- Tailwind CSS and Alpine.js
- Vite
- Pest
- Laravel Pint
- PHPStan with Larastan
