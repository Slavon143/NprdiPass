# NordiPass

NordiPass is a Laravel application. The repository currently contains the R0 Foundation Stage 1 bootstrap, Stage 2 core database, Stage 3 tenancy context, and the Stage 4 authorization foundation. Member-management UI and later product modules remain intentionally deferred.

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
```

The application is available at `http://localhost:8000`. Register a local user or sign in through the Laravel Breeze authentication routes. Local seed users use the password `password`.

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
