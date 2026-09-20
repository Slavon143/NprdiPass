# NordiPass Agent Guide

## Project

NordiPass is a Laravel application for tenant-aware product catalogues, Digital Product Passports, document compliance workflows, production import/export, and the Fortnox integration foundation. The app uses a shared database/schema: `Company` is the tenant, and tenant isolation is enforced explicitly through current-company relationships and `company_id` filters rather than a global tenant scope.

## Stack

- PHP 8.4, Laravel 13, Laravel Breeze, Laravel Sanctum.
- Blade, Tailwind CSS, Alpine.js, Vite, Node.js 22.
- MySQL or MariaDB for local, testing, staging, and production.
- Pest for tests, Laravel Pint for formatting, PHPStan/Larastan for static analysis.
- Spatie Laravel Activitylog with a custom immutable `AuditLog` model.

## Important Paths

- `app/Actions/` contains transactional business operations. Prefer actions over controller-side mutation.
- `app/Authorization/`, `app/Policies/`, and `app/Enums/CompanyPermission.php` define company authorization.
- `app/Tenancy/` resolves and validates the selected company.
- `app/Models/Catalog/`, `app/Actions/Catalog/`, `app/Services/Catalog/`, and `resources/views/catalog/` implement catalogue workflows.
- `app/Actions/Passports/`, `app/Services/Passports/`, `config/passport_readiness.php`, and `resources/views/passports/` implement passport drafts, readiness, and publication.
- `app/Services/Fortnox/`, `app/Actions/Fortnox/`, `routes/fortnox-api.php`, and `config/fortnox.php` implement the Fortnox foundation.
- `docs/API.md` and `docs/api/openapi-v1.yaml` are the API contract sources.
- `docs/architecture/` and `docs/releases/` contain accepted domain decisions and release evidence.

## Invariants

- Never trust request fields such as `company_id`, `company_uuid`, or `tenant_id` to establish tenant context.
- Tenant resources must be loaded through the current `Company` relationship or by querying both `company_id` and the resource UUID.
- Platform `super_admin` is separate from company membership and does not bypass tenant authorization.
- Company role changes, member removals, invitation acceptance, token revocation, publication, import/export apply, and Fortnox sync/conflict flows are data-integrity boundaries; inspect transactions, row locks, authorization, audit writes, and side effects.
- Raw secrets, token hashes, invitation links, OAuth credentials, cookies, authorization headers, and session values must not be logged, audited, or returned.
- Published passport snapshots and audit rows are intended to be immutable.
- Queue jobs and Artisan commands must receive scalar tenant identifiers and load tenant state explicitly inside the job/command.

## API Contract

API v1 responses use `data`, `meta`, and `error`, include `X-Request-ID`, use UUID resources, and preserve stable error codes from `docs/API.md`. Keep `docs/api/openapi-v1.yaml` synchronized with API behavior.

## Commands

Use the narrowest relevant verification first, then broaden when risk justifies it:

- Focused tests: `php artisan test --filter=<name>`
- Full tests: `composer test`
- Format check: `composer lint`
- Static analysis: `composer analyse`
- Full quality gate: `composer quality`
- Frontend build: `npm run build`

On Windows, prefer Composer scripts over direct `vendor/bin` invocations.

## Definition Of Done

For behavioral changes, inspect the relevant implementation and tests, fix the root cause, add or update focused regression coverage, run the relevant verification, inspect the final diff, and report any pre-existing failures separately. Do not commit or push unless explicitly asked.
