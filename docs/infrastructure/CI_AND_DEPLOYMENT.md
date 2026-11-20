# CI and deployment

## Gates and release build

The CI backend uses PHP 8.4 and MySQL 8.0. It runs the Laravel suite, PHPStan,
Pint, dependency audit, cache/route/scheduler checks, and executable Python
release-tooling tests on Linux. Frontend CI uses Node.js from `.nvmrc` (22),
`npm ci`, build and production dependency audit.

Release Build installs locked production dependencies, compiles assets, and uses
`deploy/scripts/release_artifact.py build`. The archive includes application
code, configuration, migrations, views, routes, vendor, artisan and build assets.
It excludes credentials, runtime caches, all storage, public/storage, public/hot,
and development directories. FILES.json hashes every payload file; RELEASE.json
identifies commit/ref, timestamp, PHP, source digest and schema contract. New
format-2 archives require the exact observed Node 22 version through
`--node-version "$(node -p 'process.versions.node')"`; a wildcard or another major
version is rejected before artifact creation. Format-1 archives remain verifiable
for historical recovery, but their `22.x` value is not measured build provenance.
Verification rejects missing runtime files, checksum/manifest changes, duplicate
paths, traversal, symlinks and special files.

GitHub keeps the transfer artifact for seven days. This is not a recovery archive.
The server retains verified tarball, checksum and metadata at
`artifacts/<archive-sha256>/` before any migration. Deployment extracts that
retained copy and verifies the installed file tree. Releases and archives are
never automatically pruned. Protect archives from web access, keep a separately
verified off-host copy, and define retention against passport/evidence obligations.
Back up source archives separately: the application's four storage-disk backup
does not include this deployment archive.

## Host layout and prerequisites

```text
/var/www/nordipass/
  current -> releases/<release-id>/
  releases/<release-id>/ARTIFACT_SHA256
  artifacts/<archive-sha256>/release.tar.gz
  shared/.env
  shared/storage/{app,framework,logs}/
  deploy/scripts/{deploy.sh,rollback.sh,release-common.sh,release_artifact.py}
  deploy.lock
```

The root and shared data must already exist. Linux requires Bash, GNU coreutils
(realpath -e and mv -T), GNU tar, flock, curl, Python >=3.10 and PHP 8.4 with the
application extensions. Release/archive directories must not be symlinks.
Only a trusted deployment operator may write code, archives and hook executables.

## Required site hooks

All operations require five absolute executable hook paths. Writer/backup hooks
receive the verified application root; `verify-release` receives both that root
and the candidate release directory. Every hook must return nonzero on failure.
These depend on the actual proxy/supervisor/scheduler topology and must be
configured and rehearsed at the target site.

| Hook | Required behavior |
|---|---|
| pause-writers | Block mutating HTTP traffic, pause cron/scheduler and queue consumers, drain in-flight requests/jobs and stop all old code writers. |
| verify-writers-stopped | Independently prove HTTP, workers, scheduled tasks, DB events and other writers are stopped; pending jobs may remain unreserved. |
| verify-backup | Verify a backup made after the drain, its checksum, schema/triggers, actual isolated restore evidence, file recovery and off-host executable-archive recovery. A file-exists check is insufficient. |
| resume-writers | Start fresh web/queue/scheduler processes against current, verify supervisor state, then reopen writes. Existing long-lived workers must not continue with old code. |
| verify-release | Read-only site integration/environment policy. Reject a candidate that loses required account restrictions or enables unintended external authorizations. Do not buy licenses, exchange/renew tokens or change connections. |

Laravel maintenance mode and queue:restart alone do not establish this boundary.
The hooks must suppress secrets in logs. Health URLs test only HTTP readiness;
they do not prove receiver deliveries, supervisor state or scheduler operation.

## Deployment sequence

Deploy Production downloads the explicitly selected Release Build run, verifies
the archive and pinned SSH host key, then transfers all four script files. Server:

1. Validate paths/hooks, release ID and archive; take a nonblocking flock.
2. Retain the verified executable archive; stop writers, verify drain and backup.
3. Create a new release directory, extract the retained archive, verify its tree.
4. Link shared environment/storage, verify the site release policy and run migrate --force.
5. Run release:verify-schema, repair capacity counters under the stopped boundary,
   then run catalog:capacity-reconcile --all-companies without repair to prove zero drift.
6. Link storage and rebuild caches; atomically switch current.
7. Resume fresh processes through the site hook; require successful /up and /ready.

The deploy.lock inode is retained after the lock is released. Removing it would
allow a later process to bypass an existing lock holder. A failure is nonzero.
Writers are held stopped; a failure after resume attempts another drain. There is
no automatic database rollback, previous-code switch, cleanup or silent success.
Inspect code/schema/writer state before explicitly resuming.

## Staging auto-deployment

`Deploy Staging` runs after a successful `CI` workflow run for a push to
`master`. Pull requests, failed or cancelled CI, tags and manual Release Build
runs do not trigger it. The workflow checks out the exact CI `head_sha`, rebuilds
the executable release with production dependencies and frontend assets, verifies
the archive, and deploys through the `staging` GitHub environment. It does not
upload a transfer artifact or change `Deploy Production`, which remains manual.

Before pushing this workflow to `master`, configure the `staging` environment
with its own `DEPLOY_HOST`, `DEPLOY_PORT`, `DEPLOY_USER`, `DEPLOY_PATH`,
`DEPLOY_HEALTH_URL`, `DEPLOY_SSH_KEY` and `DEPLOY_HOST_KEY` secrets and the same
five hook variables listed below. Their values must point to staging and its
reviewed site policy, never to production. Changes to files consulted by the
staging `verify-release` policy must be reviewed and the hook rehearsed before
they reach `master`; otherwise a legitimate automatic deploy can fail after
writers are paused and require the documented manual recovery. Configure
environment protection and restrict the deployment branch as required by the
site owner; if reviewers are enabled, the deployment pauses for approval.

## Deployment configuration

Secrets: DEPLOY_HOST, DEPLOY_PORT (default 22), DEPLOY_USER, DEPLOY_PATH,
DEPLOY_SSH_KEY, DEPLOY_HOST_KEY (a full pinned known_hosts entry), DEPLOY_HEALTH_URL.
Staging and production environment variables: DEPLOY_PAUSE_WRITERS,
DEPLOY_VERIFY_WRITERS_STOPPED, DEPLOY_RESUME_WRITERS, DEPLOY_VERIFY_BACKUP,
DEPLOY_VERIFY_RELEASE. Keep separate values in the two GitHub environments.
Production needs its own reviewed policy implementation;
the Oderland staging policy intentionally rejects any other application root.
Do not substitute a success-only stub. Updating workflow source does not install
or configure these hooks in production.
The SSH workflow accepts conventional ASCII host/user and absolute paths without
spaces; host cannot start or end with a dot/hyphen or contain `..`, user cannot
start with a hyphen, and paths cannot contain `..` or `//`. Port must be 1..65535.
A health base URL must be http(s) without whitespace or userinfo. Use GitHub
environment protection for the actual deployment.

## Explicit rollback and recovery

```bash
bash deploy/scripts/rollback.sh --app-root /var/www/nordipass \
  --release-id 20261004-previous --force --health-url https://example.com \
  --pause-writers /opt/nordipass/pause-writers \
  --verify-writers-stopped /opt/nordipass/verify-writers-stopped \
  --verify-backup /opt/nordipass/verify-backup \
  --verify-release /opt/nordipass/verify-release \
  --resume-writers /opt/nordipass/resume-writers
```

Supply flags and values as separate arguments. The rollback target is explicit,
its installed tree is verified against the retained archive. The read-only site
policy runs before pause and again under the deployment lock. An unsafe historical
integration is rejected before downtime, backup or application execution. Writers
and backup are checked before switching. Its readonly release:verify-schema must accept the
current database: the exact migration set, dedicated capacity table and required
triggers must match. Older releases without this guard are refused. An old code
release with a different migration set is refused even for additive migrations.
This conservative contract does not infer compatibility.

Rollback never runs migration down methods or repairs counters. Incompatible
code needs a reviewed forward fix or a coordinated database/file/code restore
while writers remain stopped. Preserve the current backup before restoring.
Rehearse that recovery on an isolated database; never use migrate:fresh,
migrate:refresh or db:wipe in deployment.

Scheduler and supervisor entry points must resolve current/artisan when starting
fresh processes. Target-site SSH, proxy, hooks, actual restoration and realistic
supplier/import/receiver acceptance remain separate evidence from fixture tests.
