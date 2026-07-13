# Queues and Scheduler

## Overview

NordiPass uses Laravel's queue and scheduler for background processing. The queue supports `database` (default for local) and `redis` (production recommendation) drivers.

## Queue drivers

### Local

```env
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

No external services required. Jobs are stored in the `jobs` database table.

### Production

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

Redis is the recommended production driver. See [ENVIRONMENTS.md](ENVIRONMENTS.md) for the full configuration table.

## Queue names

Three queues are defined by convention:

| Queue | Purpose | Tries | Timeout | Backoff |
|-------|---------|-------|---------|---------|
| `mail` | Invitation notifications, other email | 3 | 60s | 60s, 300s, 900s |
| `maintenance` | Reserved for future maintenance jobs | — | — | — |
| `default` | All other background jobs | 3 | 300s | 60s, 300s |

> Pruning commands (`nordipass:prune-*`, `queue:prune-failed`) run directly via the scheduler, not through a queue worker. See [Scheduler](#scheduler) below.

## Worker command

### Local

```bash
php artisan queue:work --queue=mail,maintenance,default --sleep=1 --tries=3 --timeout=300
```

Processes queues left-to-right by priority. Run in a dedicated terminal.

### Production (Supervisor)

See `deploy/supervisor/nordipass-worker.conf.example`. One worker process handles all three queues. For higher throughput, increase `numprocs` or split into dedicated workers:

```bash
# Dedicated mail worker
php artisan queue:work redis --queue=mail --tries=3 --timeout=60

# Dedicated maintenance/default worker
php artisan queue:work redis --queue=maintenance,default --tries=3 --timeout=300
```

## Timeout alignment

Job timeout, worker timeout, `retry_after`, and Supervisor `stopwaitsecs` must be consistent:

| Setting | Value | Notes |
|---------|-------|-------|
| `retry_after` | 360s (config) | Must be > worker timeout |
| Worker `--timeout` | 300s | Must be >= max job timeout |
| Job `$timeout` | 60s (mail), 300s (maintenance) | Must be <= worker timeout |
| `stopwaitsecs` | 360s (Supervisor) | Must be >= retry_after |

**Rule**: `retry_after > worker timeout >= job timeout`

## Failed jobs

Failed jobs are stored in the `failed_jobs` table with UUID identifiers.

### Commands

```bash
php artisan queue:failed              # List failed jobs
php artisan queue:retry all           # Retry all failed jobs
php artisan queue:forget <id>         # Delete one failed job
php artisan queue:flush               # Delete all failed jobs
```

### Security

Failed job payloads may contain sensitive data including invitation acceptance URLs. Restrict access to the `failed_jobs` table in production:
- Do not expose failed jobs data through any UI.
- Use database access controls to limit who can read the table.
- Prune old failed jobs regularly (see Scheduler below).

### Retention

Failed jobs are pruned after **7 days** via the daily scheduler:

```bash
php artisan queue:prune-failed --hours=168
```

## Scheduler

All scheduled tasks run in UTC. The scheduler is triggered by a single cron entry:

```bash
* * * * * cd /path/to/nordipass && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

### Local scheduler

For local development, use:

```bash
php artisan schedule:work
```

### Scheduled tasks

| Command | Frequency | Overlap lock | Output log |
|---------|-----------|-------------|------------|
| `nordipass:prune-invitations` | Daily | 180 min | `storage/logs/scheduler-prune-invitations.log` |
| `nordipass:prune-audit-logs` | Daily | 180 min | `storage/logs/scheduler-prune-audit.log` |
| `nordipass:prune-api-tokens` | Daily | 180 min | `storage/logs/scheduler-prune-api-tokens.log` |
| `queue:prune-failed --hours=168` | Daily | 60 min | `storage/logs/scheduler-prune-failed-jobs.log` |

All pruning tasks:
- Use `withoutOverlapping()` to prevent concurrent runs.
- Are idempotent and chunked to avoid large transactions.
- Support `--dry-run` for safe inspection.

## Queue restart

After a deployment, gracefully restart the queue workers:

```bash
php artisan queue:restart
```

This does **not** use `kill -9` or `taskkill /F`. It signals workers to restart after finishing their current job.

## Redis readiness

Redis is configured in `config/database.php`, `config/cache.php`, `config/queue.php`, and `config/session.php`. Redis is **not** a runtime dependency for local development.

### Prefix

The application uses a Redis prefix to avoid key collisions when multiple applications share one Redis instance:

```php
// config/database.php — 'redis.options.prefix'
'nordipass-database-'
```

```php
// config/cache.php — 'prefix'
'nordipass-cache-'
```

### Database separation

| Purpose | Redis DB |
|---------|----------|
| Cache | `REDIS_CACHE_DB=1` |
| Queue/Session | `REDIS_DB=0` (default) with prefixes |

Managed Redis providers (e.g., Upstash, AWS ElastiCache) may ignore database numbers. In that case, prefix-based isolation is used instead.

### Failure behavior

If Redis is unavailable, the application fails explicitly — there is no silent fallback to the database driver. The operator must either restore Redis or update the environment configuration to use the database driver.
