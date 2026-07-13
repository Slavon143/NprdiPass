# Release Checklist

## Pre-release

- [ ] CI pipeline is green for the target commit
- [ ] All tests pass (backend + frontend)
- [ ] PHPStan analysis shows no errors
- [ ] Pint code style check passes
- [ ] No new `composer audit` vulnerabilities at `high` or above
- [ ] No new `npm audit` vulnerabilities at `high` or above
- [ ] Migration risk has been reviewed (see below)
- [ ] Verified backup exists and is less than 26 hours old
- [ ] Release artifact has been built and SHA-256 verified

## Migration Risk Review

| Risk | Check |
|------|-------|
| Table lock | Reviewed |
| Full table rewrite | Reviewed |
| Large index creation | Reviewed |
| NOT NULL without backfill | Reviewed |
| Column rename/drop | Reviewed |
| Table drop | Reviewed |
| Data conversion | Reviewed |

## Deployment

- [ ] Environment approval obtained
- [ ] Deployment lock is available
- [ ] Maintenance mode is ready (if required)
- [ ] `php artisan migrate --force` completes
- [ ] Config, route, and view caches build
- [ ] `current` symlink atomically switched
- [ ] Queue workers restarted
- [ ] `/up` responds 200
- [ ] `/ready` responds 200
- [ ] Smoke test passes (safe read-only)

## Post-deployment

- [ ] Previous release is retained
- [ ] Old releases cleaned up (keep last 5)
- [ ] Scheduler cron still pointing to `current/artisan`
- [ ] Deployment documented (release ID, commit SHA, time)

## Rollback

- [ ] Previous release identified
- [ ] `deploy/scripts/rollback.sh --force` executed
- [ ] `/up` and `/ready` verified after rollback
- [ ] Database schema compatibility noted
