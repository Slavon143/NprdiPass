# Oderland Redis server verification

Verified over SSH on 2026-10-09 at 10:06:14 UTC, using the user-authorised server
and its previously recorded, pinned ED25519 host key. Authentication used the
provided encrypted key through terminal input; no credential was added to the
project, server commands or this report.

The target application was **NordiPass staging**, reached through
`/home/codecraf/nordipass-staging/current`. Its resolved release was
`staging-source-20261007-3b3dd0fb5f00`. This is not evidence of a production
deployment or of the newer local changes being deployed.

## Observed configuration

| Setting | Observed value |
|---|---|
| PHP CLI | 8.4.26 |
| Laravel | 13.30.0 |
| Application environment | staging |
| Configuration cache | present |
| Queue / cache / session drivers | database / database / database |
| Configured Redis client | phpredis |
| PhpRedis extension | 6.3.0 |
| Predis in deployed vendor | absent |
| Unix socket | `/tmp/redis`, socket, owned by `codecraf`, mode `700` |
| Redis version | 6.2.24 |
| maxmemory | 128000000 bytes (about 122 MiB) |
| Eviction policy | allkeys-lru |
| AOF | disabled (`aof_enabled=0`) |
| Last RDB background-save status | ok |

## Executed checks

A fresh Laravel Redis manager used the existing PhpRedis extension with the
Unix socket as host, port 0 and databases 0 and 1. Configuration was overridden
only in that diagnostic process; the running application's configuration and
singleton manager were not modified.

Both connections answered PING. A randomly named temporary cache entry was
written, read back, removed and confirmed absent. A ten-second owned lock was
acquired, rejected a second owner and was successfully released.

An isolated randomly named diagnostic queue started empty. A synthetic raw
payload was pushed, its size became one, and the job was reserved and deleted.
The queue returned to zero. All four exact keys belonging to that diagnostic
queue were removed and confirmed absent. The job handler was never executed;
no business queue was popped, cleared or modified. No `FLUSHDB`, `FLUSHALL`,
`cache:clear`, Redis configuration change or application migration was run.

This proves socket access and Laravel cache/lock/queue backend operations from
the CLI account. It does not prove HTTP/FPM access, a persistent worker executing
business jobs, a functioning scheduler or queue durability under resource
pressure or a Redis/server restart.

The initial process-command snapshot saw four account-level queue workers and
none whose command explicitly identified the NordiPass staging path. That is
not proof of absence: relative Artisan commands require checking their working
directories. No `schedule:run` entry was found in the account's readable crontab;
external scheduler configuration was not inspected. Two subsequent read-only
attempts to verify worker working directories and database job counts were
interrupted by SSH disconnection, so those results remain unconfirmed.

## Operational conclusion

Keep NordiPass's current database queue, session and default-cache drivers on
this shared instance. `allkeys-lru` permits automatic key removal at the memory
limit, including non-expiring queue and coordination keys. Logical databases or
prefixes do not provide independent eviction limits. Redis is suitable here for
explicitly selected regenerable cached data, not as a durability boundary.
This follows the observed policy and the
[Redis eviction documentation](https://redis.io/docs/latest/develop/reference/eviction/).

The default cache also backs NordiPass backup locks and scheduler overlap
protection. Switching the entire cache driver would therefore move more than
ordinary data into this evicting Redis instance. Separate durable coordination
from disposable caches before any such change. An `ok` RDB save status with AOF
disabled does not establish per-write durability or a provider recovery policy;
see [Redis persistence](https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/).

No environment file, active configuration cache, application code, live worker,
scheduler or Redis server setting was changed. No paid purchase was made. The
server-specific recommendations in the infrastructure documentation now reflect
these observations. Further worker/HTTP checks and any persistent activation
need their own concrete acceptance; the connectivity test is complete.
