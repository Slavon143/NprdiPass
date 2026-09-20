# R3.15 Platform Operations

R3.15 adds the first restricted platform-operations surface for support, tenant health, feature flag audit, incidents, and abuse-control signals.

## Implemented

- Added explicit `platform.permission` middleware backed by Spatie platform permissions.
- Added `platform_operations` feature flag (ADR R3-010) gating the ops routes; env `FEATURE_PLATFORM_OPERATIONS`, default off in production.
- Added platform operations routes under `/platform/operations`.
- Added tenant health snapshots for members, products, failed/pending jobs, unread notifications, and open incidents.
- Added time-boxed `platform_support_access_grants` with grant/revoke support.
- Added audited `platform_feature_flag_overrides` and `FeatureFlagManager`.
- Added `platform_incidents` and `platform_abuse_events` tables with report/resolve incident workflows surfaced on the dashboard and the company page.
- Added platform operations dashboard and per-company operations page.

## Security boundaries

- Platform access is separate from tenant membership and does not bypass tenant authorization.
- Support access grants are explicit, time-boxed, and audited.
- Feature flag override changes are audited with actor, flag, scope, and target company snapshot.
- Tenant health is aggregated by explicit company IDs; foreign tenant markers are not shown on company detail pages.

## Deferred to later stages

- Platform-level failed-job recovery and metrics/alerting: R3.17 (observability telemetry).
- Abuse-control signal pipeline (recording/displaying `platform_abuse_events`): R3.16 security hardening.
- Fortnox status and analytics signals on the ops dashboard: R3.17.
- Incident/backup/restore runbooks: R3.17, final in R3.19 (Operations Gate).

## Verification

- `php artisan migrate --force`
- `php artisan test tests\Feature\Platform\PlatformOperationsTest.php`
- `php artisan route:list --path=platform/operations`
- `vendor\bin\pint --test ...`
- `composer analyse`