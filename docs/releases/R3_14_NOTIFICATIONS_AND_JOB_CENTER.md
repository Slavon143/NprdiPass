# R3.14 Notifications and Job Center

R3.14 adds the tenant-aware in-app notification center, user notification preferences, and a first job-center foundation for visible async work.

## Implemented

- Added notification types for document expiry, imports, Fortnox sync failure, publication failure, readiness changes, and billing events.
- Added `notifications`, `notification_preferences`, and `job_statuses` tables.
- Added tenant-scoped models for notifications, preferences, and job statuses.
- Added `NotificationCenter` service with company/user fanout, preference checks, and idempotent notification creation.
- Added `JobStatusTracker` service with pending/running/succeeded/failed/cancel/retry state transitions.
- Added web routes and pages:
  - `GET /notifications`
  - `POST /notifications/mark-all-read`
  - `POST /notifications/preferences`
  - `POST /notifications/{notification}/read`
  - `POST /notifications/{notification}/dismiss`
  - `GET /job-center`
  - `GET /job-center/{job}`
  - `POST /job-center/{job}/cancel`
  - `POST /job-center/{job}/retry`
- Added navigation entries for Notifications and Job center.
- Added `FEATURE_NOTIFICATIONS_CENTER` configuration for rollout.

## Security boundaries

- Notification and job queries are scoped to the current company.
- User notification actions are scoped to both current company and current user.
- Job status payload summaries store scalar diagnostic data instead of hard FK dependencies on domain entities, so deleted products/documents do not break job detail pages.
- Idempotency keys prevent duplicate in-app notifications and duplicate tracked job registrations per company.
- Retry creates a new job-status row linked to the original attempt instead of mutating historical results.

## Verification

- `php artisan migrate --force`
- `php artisan test tests\Feature\Notifications\NotificationCenterTest.php`
- `php artisan route:list --path=notifications`
- `php artisan route:list --path=job-center`
- `vendor\bin\pint --test ...`
- `composer analyse`
