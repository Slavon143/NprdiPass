# CI & Deployment

## Overview

NordiPass uses GitHub Actions for CI, release build, and production deployment workflows.

## CI Workflow

Triggered on `pull_request` and `push` to `master`. Runs in parallel:

### Backend job (ubuntu-latest)

- PHP 8.4 with MySQL 8.0 service container
- `composer validate --strict`
- `composer install` (locked)
- `php artisan migrate:fresh --seed`
- `php artisan test`
- `./vendor/bin/phpstan analyse`
- `./vendor/bin/pint --test`
- `composer audit --locked`
- `php artisan config:cache`, `route:cache`, `view:cache`
- `php artisan route:list`, `schedule:list`

### Frontend job (ubuntu-latest)

- Node.js from `.nvmrc`
- `npm ci`
- `npm run build`
- `npm audit --omit=dev --audit-level=high`

## Release Build

Triggered by `workflow_dispatch` or `v*` tag. Creates an immutable artifact:

- `composer install --no-dev --classmap-authoritative`
- `npm ci && npm run build`
- **Artifact includes**: app/, bootstrap/, config/, database/, public/, resources/, routes/, vendor/, artisan, RELEASE.json
- **Artifact excludes**: .env, .git, node_modules/, tests/, storage/logs, storage/framework
- **Checksum**: SHA-256 of the `.tar.gz` file
- **Metadata**: RELEASE.json with commit SHA, ref, build timestamp

## Deployment Strategy

Release directories with atomic `current` symlink:

```
/var/www/nordipass/
├── current -> releases/20260714-abcdef/
├── releases/
│   ├── 20260714-abcdef/
│   └── 20260713-123456/
└── shared/
    ├── .env
    └── storage/
```

## Deployment Flow

1. Verify artifact checksum
2. Verify SSH host key
3. Acquire deployment lock (flock)
4. Create release directory
5. Extract artifact
6. Link shared .env and storage
7. Create storage symlink
8. Check permissions
9. Run `php artisan migrate --force`
10. Build config, route, view caches
11. Atomic `current` symlink switch
12. `php artisan queue:restart`
13. `/up` and `/ready` health checks
14. Cleanup old releases (keep last 5)

## Rollback

```bash
deploy/scripts/rollback.sh --app-root=/var/www/nordipass --force --health-url=https://example.com
```

- Switches `current` symlink to previous release
- Restarts queue workers
- Verifies `/up` and `/ready`
- **Does not** roll back database migrations

## Required Secrets

| Secret | Purpose |
|--------|---------|
| `DEPLOY_HOST` | Production server hostname |
| `DEPLOY_PORT` | SSH port (default: 22) |
| `DEPLOY_USER` | SSH user |
| `DEPLOY_PATH` | Application root on server |
| `DEPLOY_SSH_KEY` | Private SSH key for deployment |
| `DEPLOY_HOST_KEY` | Expected SSH host key fingerprint |
| `DEPLOY_HEALTH_URL` | Base URL for health checks |

## Deployment Lock

- Server-side `flock` on `deploy.lock` file
- CI concurrency group prevents parallel deployments
- Lock released on completion, failure, or trap

## Migration Strategy

- **Expand/Contract**: Backward-compatible changes first, removal in subsequent release
- **Production**: `php artisan migrate --force` only
- **No**: `migrate:fresh`, `migrate:refresh`, `db:wipe` in deployment
- **Rollback**: Code can be rolled back; database cannot be automatically rolled back

## Scheduler

Cron entry must point to `current/artisan`:

```bash
* * * * * cd /var/www/nordipass/current && php artisan schedule:run >> /dev/null 2>&1
```

## Known Limitations

- Database rollback is not automated
- Queue worker health is not verified by /ready
- Production SSH is not fully verified in CI
- Deployment requires Linux host with symlink support
