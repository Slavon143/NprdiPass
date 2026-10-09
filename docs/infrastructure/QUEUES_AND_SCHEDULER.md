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

### Production with durable Redis

This example requires a Redis instance whose memory/eviction and persistence
policy is suitable for queues, sessions and coordination locks. It is not the
current Oderland shared Redis configuration; see the server-specific section.

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

Redis is the recommended production driver. See [ENVIRONMENTS.md](ENVIRONMENTS.md) for the full configuration table.

### Oderland Unix socket (Laravel 13)

The hosting instructions supplied for this project use a local Unix socket
instead of a TCP port. NordiPass includes the free `predis/predis` client and
passes `REDIS_SCHEME` and `REDIS_PATH` to both Redis connections. Optional
transport settings are omitted when unset, preserving existing TCP/TLS handling.
The socket path below was verified over SSH on NordiPass staging on 2026-10-09.
The instance reported Redis 6.2.24, `maxmemory=128000000`, `allkeys-lru` and
`aof_enabled=0`. Successful operations do not establish durable queue storage:
under memory pressure, all-key eviction can remove queue, session and lock keys.
Different logical Redis databases and prefixes do not isolate that memory policy.
Keep queues, sessions and the default coordination cache in MySQL for this host.
Use Redis explicitly only for regenerable cached data with reviewed consumers.

```env
REDIS_CLIENT=predis
REDIS_SCHEME=unix
REDIS_PATH=/tmp/redis
REDIS_PASSWORD=null
REDIS_PORT=0
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_PREFIX=nordipass-production-database-
CACHE_PREFIX=nordipass-production-cache-

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_CONNECTION=null
```

Keep `REDIS_URL` unset so it does not override the socket parameters. Keep local
and testing drivers as defined above. Use distinct Redis and cache prefixes for
staging and production. Laravel 13 reads `CACHE_STORE`; the older guide's
`CACHE_DRIVER` variable does not configure this application. Ordinary data can
use `Cache::store('redis')`; do not move critical locks into this evicting store.
NordiPass currently uses default-cache locks for backup and scheduled overlap
prevention, so setting `CACHE_STORE=redis` would also move coordination into Redis.
For an independently approved durable Redis session setup,
`SESSION_CONNECTION=default` selects Redis database 0. MySQL sessions should
leave `SESSION_CONNECTION` unset or null.

Predis is the configured socket route for Oderland. Modern PhpRedis also supports
Unix sockets, so the old statement that the extension cannot use sockets is not
a general limitation. Its connector uses a different configuration; do not mix
the Predis `scheme/path` recipe with PhpRedis without adapting and checking it.
The current staging release has PhpRedis 6.3.0 and no Predis package. Its socket
connection, cache, locks and isolated queue round-trip passed through Laravel
13.30.0 over SSH. It can use the following alternative transport settings;
`REDIS_SCHEME`, `REDIS_PATH` and `REDIS_URL` must be unset for this recipe:

```env
REDIS_CLIENT=phpredis
REDIS_HOST=/tmp/redis
REDIS_PORT=0
REDIS_PASSWORD=null
REDIS_DB=0
REDIS_CACHE_DB=1
```

The temporary test used a new in-memory Redis manager and did not change the
running application's environment, cached configuration, clients or workers.

Activation procedure on the server (durable driver changes require a suitable
Redis instance and their own cutover):

1. Install the locked production dependencies with the existing release process.
   `composer install --no-dev --prefer-dist --no-interaction` includes Predis.
2. Confirm that `/tmp/redis` is a socket visible to the account running web PHP
   and the CLI worker. Do not change socket ownership or permissions blindly.
3. Keep database queues on the currently verified Oderland instance. Before an
   approved move to a suitable durable Redis instance, stop new dispatches and
   drain the existing database queues using the old configuration. Changing
   `QUEUE_CONNECTION` does not move already queued database jobs into Redis.
4. Apply the selected transport and driver settings for the intended environment and run
   `php artisan config:cache` from the active release with the same PHP runtime
   as the application. Session switching requires users to sign in again.
5. Verify both connections from that account. In an interactive
   `php artisan tinker` session, run
   `Illuminate\Support\Facades\Redis::connection('default')->ping()` and the
   same call with `'cache'`. Expect a PONG-equivalent response. Test one unique
   cache key with a short TTL and remove only that key. Do not use `FLUSHDB`,
   `FLUSHALL` or `cache:clear` as a connectivity probe: prefixes do not scope
   those database-wide operations.
6. Select process management supported by the actual hosting plan. Oderland's
   October 9 reply to ticket 274357 confirms that Standard cannot run persistent
   workers or Horizon and kills PHP processes after 15 minutes. The provider's
   cPanel persistent-worker module requires Premium/Deluxe; no upgrade was ordered.
   Keep the existing database-backed short cron workers on Standard. For a
   separately accepted eligible host, persistent worker arguments are
   `queue:work database --queue=webhooks,imports,mail,analytics,maintenance,default --sleep=1 --tries=5 --timeout=300`.
   `queue:restart` signals an exit; it does not create a replacement process.
   Keep the scheduler's once-per-minute invocation in place.
7. Verify an approved application job and its persisted outcome. Monitor queue
   age, failed jobs and memory. Confirm the provider's persistence/eviction policy
   before relying on Redis for queued work; no server acceptance is implied by
   the local connector tests.

No server environment, configuration cache or live workers are changed by the
repository configuration update.

Local verification on 2026-10-09: Predis 3.7.0 is locked under `^3.4`; three
Laravel/Predis connector tests passed with 16 assertions, covering both socket
connections, database selection, prefixing and implicit TCP/TLS handling without
opening a network connection. Composer validation, scoped Pint and PHPStan
passed. The existing PHP runtime duplicate-extension/autoload fixture warnings
remain unrelated to Redis configuration. SSH verification subsequently passed
both connection PINGs, temporary cache write/read/cleanup, exclusive lock/release
and private queue push/reserve/delete/cleanup. No business job was executed and
no live driver was switched. Worker execution, scheduler operation and HTTP/FPM
acceptance remain separate checks. See
[server evidence](../releases/2026_10_09_ODERLAND_REDIS_SERVER_CHECK.md).

Sources: [Oderland socket guide supplied by the user](https://www.oderland.se/support/artikel/hur-far-jag-igang-redis-via-socket-for-laravel/),
[Oderland cPanel workers](https://www.oderland.se/support/en/artikel/how-do-i-manage-laravel-workers-via-cpanel/),
[Laravel 13 Redis](https://laravel.com/docs/13.x/redis),
[Predis Unix socket parameters](https://github.com/predis/predis#connecting-to-redis),
[PhpRedis connection parameters](https://github.com/phpredis/phpredis#connection),
[Redis eviction policy](https://redis.io/docs/latest/develop/reference/eviction/).

### Standard hosting process boundary

The provider additionally confirms that Redis data does not survive Redis/server
restart. Database queues, sessions and coordination therefore remain the selected
configuration. See the authenticated [support ticket list](https://www.oderland.se/client-area/tickets)
and [current acceptance](../releases/2026_10_09_RELEASE_CONTINUATION.md).

A finite cron worker can use `--stop-when-empty --max-time=480 --timeout=300`
on the database connection. The 480-second worker budget plus one final
300-second job leaves 120 seconds under the reported 900-second process ceiling.
`--max-time` is checked between jobs, so setting it to 840 or 900 does not provide
that margin. This is a prepared bound, not an installed or accepted replacement
for the site's existing cron scripts. Use a retained per-environment `flock`
inode to prevent overlap, and inspect queue fairness and oldest-job age under
load: queue-list order is priority order, not guaranteed fairness.

Deployment must pause the cron entry points and verify existing processes have
finished before migrations. Maintenance mode alone does not drain CLI writers.
An owned idle PHP CLI probe on October 9 completed normally after 920 seconds
with `max_execution_time=0`; the provider's stated 15-minute policy did not stop
that specific process at 900 seconds. This is neither persistent-worker support
nor a reliable stop deadline. Do not infer drain from waiting for that nominal
limit; explicitly verify the actual processes/locks and HTTP boundary.
Production acceptance must identify and rehearse the actual HTTP/cron/worker
stop/start boundary. A fresh heartbeat proves a recent invocation; it does not
prove permanent supervision, writer drain or target throughput.

## Queue names

Six queues are defined by convention:

| Queue | Purpose | Tries | Timeout | Backoff |
|-------|---------|-------|---------|---------|
| `mail` | Invitation notifications, other email | 3 | 60s | 60s, 300s, 900s |
| `maintenance` | Reserved for future maintenance jobs | — | — | — |
| `default` | All other background jobs | 3 | 300s | 60s, 300s |
| `webhooks` | Outbound webhook delivery | 5 | 30s | 60s, 300s, 900s, 1800s |
| `imports` | Confirmed large product imports | 1 | 300s | — |
| `analytics` | Public passport analytics events | Worker default | Worker default | Worker default |

Large import jobs apply one bounded chunk and enqueue a continuation only when
the cursor advances and work remains. Synchronous processing retains its loop.
Jobs reload company state from scalar identifiers and restore worker tenant
context after every execution, including failures.

`webhooks:recover` runs every minute and requeues at most 100 stale/due pending
deliveries (CLI `--limit` is bounded at 1000). New undelivered outbox rows become
eligible after five minutes; interrupted attempt leases and HTTP retry backoff
use `next_attempt_at`. Atomic claims prevent concurrent duplicate jobs from
starting the same attempt, and five persisted attempts exhaust the budget.
Monitor dead letters; the scheduler is required to recover commit/dispatch gaps.
Delivery remains at-least-once and uses a stable receiver idempotency key.

> Pruning commands (`nordipass:prune-*`, `queue:prune-failed`) run directly via the scheduler, not through a queue worker. See [Scheduler](#scheduler) below.

## Worker command

### Local

```bash
php artisan queue:work --queue=webhooks,imports,mail,analytics,maintenance,default --sleep=1 --tries=5 --timeout=300
```

Processes queues left-to-right by priority. Run in a dedicated terminal.

### Production (Supervisor)

See `deploy/supervisor/nordipass-worker.conf.example`. The example consumes all six queues and starts through `current/artisan` in the atomic release layout. Adapt the PHP binary, application root, service user and log paths to the target site. For higher throughput, increase `numprocs` or split into dedicated workers, retaining a consumer for every queue:

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
* * * * * cd /var/www/nordipass/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
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

## Webhook recovery ownership and acceptance

The environment operator owns the scheduler and the `webhooks` worker;
the company endpoint owner owns receiver availability and idempotency.
Before enabling an environment, record those owners and verify that the
scheduler runs `webhooks:recover` every minute. Recovery requeues only due
`pending` deliveries for active endpoints of the same company, with a bounded
limit. Duplicate queue dispatches still require the persisted attempt lease.

Tenant API inspection can filter deliveries by `failed` or `dead_letter` and
inspect sanitized attempt status/error codes. `queue:retry` does not reset a
terminal delivery or its five-attempt budget; recovery is not permission to
erase attempt history or blindly replay every terminal row. Diagnose endpoint
configuration and receiver acknowledgement before an explicit replay workflow.

Staging acceptance must exercise: domain rollback sends nothing; missed
after-commit dispatch is recovered; interrupted workers leave a closed attempt;
duplicate workers do not send concurrently; receiver success followed by local
acknowledgement failure may repeat the same stable event/idempotency key.
The receiver must deduplicate that key. These are at-least-once guarantees.

## Resolver timing collection

Structured `observability.request` logs contain `public_resolver_ms` when the
published-passport resolver executes. This is measured elapsed time, separate
from total request duration; other requests have a null sample. It contains no
passport URL, tenant identifier or payload. Collect these samples in the
environment's time-series system to calculate p95 over a stated time window.
An optional local collector is enabled by `OBSERVABILITY_RESOLVER_METRICS_ENABLED=true`
with `OBSERVABILITY_RESOLVER_METRICS_STORE=database` (or a configured Redis,
Memcached or file store). The database cache and lock tables must exist. All
application hosts must use the same store/prefix; file cache aggregates only
one host. Array, null, session and failover stores are deliberately unsupported.

The collector keeps 60 minute buckets, each with a fixed histogram and maximum;
TTL is 61 minutes. `p95_ms` is the nearest-rank **histogram upper bound**, not an
exact percentile, over the current minute and preceding 59 minutes. The snapshot
also exposes sample count, window start, kind and collection status. No passport,
tenant, IP or request identifiers are stored. Contended locks skip samples without
waiting; slow, failed or incomplete HTTP requests that do not return through the
middleware are not represented. Use an external APM for complete latency analysis.

Collection works independently of request logging. It is off by default; disabled,
empty, unsupported or unavailable stores keep latency `unknown`. Collection failure
does not change the passport response. Unsupported queue backends still require
their native monitoring. Monitor sample volume when enabling the collector;
cache eviction or contention can reduce coverage.

## Queue restart

Before catalogue index migrations, drain or pause affected import workers and
allow their current chunks to finish. An active import encountered MySQL 1412
during local DDL; additive indexes alone do not make that transition safe.
Validate the pause/migrate/restart procedure against the staging database version.

Imports retry schema-change, deadlock and lock-timeout failures only when the
action owns the root transaction, at most three attempts. Exhaustion leaves
committed checkpoints intact; technical failures do not mark valid rows failed.
Connection loss is not automatically replayed because commit acknowledgement
can be lost after a successful commit. Reconcile the stored checkpoint, then
use the authorized import restart flow. Queue attempt counts are unchanged.

The local interruption regression in
`tests/Concurrency/WebhookCrashRecoveryTest.php` starts a real database queue
worker with fake HTTP and exits at two delivery boundaries. It verifies lease
expiry, stable receiver identity, deduplication and no resend after persisted
success. Run it only on an isolated MySQL testing schema. See
`docs/releases/2026_10_03_XLSX_AND_OPERATIONS.md` for evidence and limits;
production receiver idempotency and worker supervision still need operational
verification.

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
