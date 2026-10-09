# Release checklist

## Before deployment

- [ ] Target commit: CI, full Laravel suite, PHPStan, Pint and frontend build pass.
- [ ] Production dependency audits have no unresolved release blockers.
- [ ] Migrations and index/trigger costs are reviewed for the target database/version.
- [ ] Real supplier XLSX and representative import/catalogue sizes are accepted.
- [ ] Tenant roles, public snapshots and billing/webhook retries are accepted on staging.
- [ ] Proxy, queue supervisor, scheduler and receiver duplicate handling are exercised.
- [ ] Four site hooks are configured and rehearsed against every writer.
- [ ] Verified executable archive and metadata exist on server and off-host; recovery works.
- [ ] Database/file backups, schema/trigger definitions and isolated restoration are verified.
- [ ] Failure before activation, failed health and incompatible rollback leave writers stopped.
- [ ] Production environment approval and pinned SSH host identity are ready.

## During deployment

- [ ] Deployment flock acquired without removing its inode.
- [ ] Archive retained and installed file tree verified.
- [ ] HTTP mutations, queue, cron/scheduler and DB events stopped and independently checked.
- [ ] Backup corresponds to the drained boundary and restoration evidence is checked.
- [ ] migrate --force and readonly release:verify-schema succeed.
- [ ] Capacity reconciliation repair completes; subsequent check has zero drift.
- [ ] Shared data links and config/route/view caches are correct.
- [ ] current atomically switched and fresh processes started through the resume hook.
- [ ] /up and /ready both return success; read-only application smoke test succeeds.

## After deployment

- [ ] Schema/counters and queued/unreserved job state are recorded.
- [ ] Supervisor and scheduler start through current; old workers are absent.
- [ ] Publication/audit immutability and tenant boundaries remain verified.
- [ ] Release ID, commit, archive/source hashes and runtime identity recorded.
- [ ] Previous code and executable archives retained under the recovery policy.
- [ ] Off-host database, file and executable backups remain recoverable.

## Rollback or recovery

- [ ] Explicit release ID and verified installed tree selected.
- [ ] Writers stopped and backup checked before rollback.
- [ ] Target release's exact schema contract passes before current changes.
- [ ] Otherwise use a reviewed forward fix or rehearsed coordinated restore.
- [ ] No automatic migration rollback or previous-code switch assumed.
- [ ] Fresh processes, health, supervisor and scheduler verified before reopening writes.

Fixture tests and local rehearsal do not tick staging/production checks.
See CI_AND_DEPLOYMENT.md for hook contracts, prerequisites and complete flags.
