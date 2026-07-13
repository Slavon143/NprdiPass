# NordiPass R0 — Infrastructure Verification

**Verified:** 2026-07-13  
**Environment:** Windows 11, PHP 8.4.21, Node 24.15.0, MySQL 8.0  
**Verification mode:** Local + Test suite + Static analysis  
**Stages verified:** 9.1–9.5

---

## 1. Scope

Cross-stage verification of all NordiPass R0 infrastructure components:
- Stage 9.1 — Base Infrastructure Configuration
- Stage 9.2 — Queue, Scheduler & Redis Readiness
- Stage 9.3 — Health, Logging & Security
- Stage 9.4 — Backup, Restore & Disaster Recovery
- Stage 9.5 — CI & Deployment

---

## 2. Environment

| Component | Local | CI | Production |
|---|---|---|---|
| PHP | 8.4.21 | 8.4 | 8.4 |
| Node | 24.15.0 | 22 | 22 |
| DB driver | mysql (SQLite for tests) | mysql (service) | mysql |
| Cache | database | (CI uses array from env) | redis |
| Session | database | (CI uses array from env) | redis |
| Queue | database | (CI uses sync from env) | redis |

---

## 3. Infrastructure Inventory

### Environment Configuration

| Component | Implemented | Tested | Verified |
|---|---|---|---|
| `.env.example` completeness | Yes | `EnvironmentExampleTest` | All infrastructure vars documented |
| `env()` in config only | Yes | grep audit | No `env()` in app/routes, only config |
| `env()` in bootstrap/app.php | Yes (expected) | N/A | TRUSTED_PROXIES, TRUSTED_HOSTS only |
| Boolean casts (filter_var) | Yes | Yes | 15 boolean vars in config/* correct |
| Integer casts | Yes | Yes | 12 integer vars correct |
| No production secrets in example | Yes | Manual | Verified |

### Database

| Component | Implemented | Tested | Verified |
|---|---|---|---|
| MySQL production driver | Yes | MySQL integration suite | Via phpunit.mysql.xml |
| SQLite in-memory tests | Yes | Full test suite | 541 tests pass |
| mysql driver backup | Yes | `BackupDetailedTest` | Only files backup verified |
| Connection configuration | Yes | DeployCheckCommand | migrates check, SELECT 1 |

### Cache

| Component | Implemented | Tested | Verified |
|---|---|---|---|
| database cache (local) | Yes | Health check | `/ready` writes/reads/forgets |
| redis cache (production) | Yes (config) | Not runtime verified | Redis not available locally |
| array cache (testing) | Yes | phpunit.xml | test environment |
| Cache prefix isolation | Yes | Config | `nordipass-cache-` |

### Session

| Component | Implemented | Tested | Verified |
|---|---|---|---|
| database driver (local) | Yes | SessionSecurityTest | SSL/HttpOnly/SameSite verified |
| redis driver (production) | Yes (config) | Not runtime verified | Redis not available |
| Secure cookie (production) | Yes | DeployCheckCommand | Fails if false in prod |
| HttpOnly | Yes | SessionSecurityTest | true |
| SameSite=lax | Yes | SessionSecurityTest | Verified |
| JSON serialization | Yes | SessionSecurityTest | Verified |

### Queue

| Component | Implemented | Tested | Verified |
|---|---|---|---|
| database driver (local) | Yes | QueueConfigurationTest | Connection, queue names |
| redis driver (production) | Yes (config) | Not runtime verified | Redis not available |
| Queue names: mail, maintenance, default | Yes | QueueConfigurationTest | Verified |
| Invitation notification queued | Yes | InvitationNotificationTest | mail queue, afterCommit |
| retry_after=360 | Yes | Config | Exceeds worker timeout=300 |

### Queue Timeout Matrix

| Setting | Value | Check |
|---|---|---|
| `QUEUE_RETRY_AFTER` | 360s | Must be > worker timeout ✓ |
| Worker `--timeout` (Supervisor) | 300s | Must be >= max job timeout ✓ |
| Mail job timeout | 60s | Per notification config ✓ |
| Supervisor `stopwaitsecs` | 360s | Must be >= retry_after ✓ |
| Supervisor `--max-time` | 3600s | Safety bound ✓ |

### Scheduler

| Command | Frequency | Overlap Lock | Purpose |
|---|---|---|---|
| `nordipass:prune-invitations` | Daily 00:00 | 180 min | Invitation history cleanup |
| `nordipass:prune-audit-logs` | Daily 00:00 | 180 min | Audit log retention |
| `nordipass:prune-api-tokens` | Daily 00:00 | 180 min | Expired token cleanup |
| `nordipass:scheduler-heartbeat` | Every minute | 5 min | Freshness signal for /ready |
| `nordipass:backup` | Daily 02:00 | 180 min | Full backup |
| `nordipass:backup-prune` | Daily 04:00 | 60 min | Retention cleanup |
| `queue:prune-failed --hours=168` | Daily 00:00 | 60 min | Failed job cleanup |

- All UTC ✓
- No duplicates ✓
- No destructive tasks every minute ✓
- Backup and prune separated (02:00 vs 04:00) ✓
- `withoutOverlapping` used on all ✓
- Heartbeat scheduled every minute ✓

### Health

| Component | Implemented | Tested | Verified |
|---|---|---|---|
| `GET /up` (liveness) | Yes | Built-in | Returns 200 when booted |
| `GET /ready` (readiness) | Yes | HealthCheckTest | DB, cache, queue checks |
| Scheduler heartbeat check | Yes | SchedulerHeartbeatTest | Fresh/stale/missing verified |
| HEALTH_DETAILS toggle | Yes | HealthCheckTest | Details on/off verified |
| async queue rejection | Yes | HealthCheckTest | sync + require_async → 503 |

### Health Failure Matrix

| Scenario | HTTP | Verified |
|---|---|---|
| Disabled health | 200 `{"status":"disabled"}` | Via config |
| All checks pass | 200 `{"status":"ok"}` | ✓ |
| Database unavailable | 503 `{"status":"unavailable"}` | Via test |
| Cache unavailable | 503 | Via test |
| Sync queue + require_async | 503 | Via test |
| Stale scheduler heartbeat | 503 | SchedulerHeartbeatTest |
| Missing heartbeat | 503 | SchedulerHeartbeatTest |
| Details enabled | 200 with `checks` object | HealthCheckTest |

### Logging

| Component | Implemented | Tested | Verified |
|---|---|---|---|
| single channel (local) | Yes | Config | Readable text |
| daily channel | Yes | Config | 14 days retention |
| daily_json channel | Yes | Config | JSON lines, 14 days |
| stderr_json channel | Yes | Config | Container/stderr |
| stack channel | Yes | Config | `LOG_STACK` env-driven |
| No sensitive data logs | Yes | LoggingRedactionTest | Auth, tokens, passwords excluded |
| context fields | Yes | EnsureRequestId middleware | request_id, user_id, company_id |

### Request ID

| Component | Implemented | Tested | Verified |
|---|---|---|---|
| Valid UUID accepted | Yes | RequestIdTest | 1-100 alphanumeric/dash/dot/underscore |
| Invalid replaced | Yes | RequestIdTest | Too long, control chars rejected |
| Missing → generated | Yes | RequestIdTest | UUIDv4 auto-generated |
| Response header | Yes | RequestIdTest | X-Request-ID always present |
| Queue context isolation | Yes | QueueContextTest | No context leak between jobs |
| Log context | Yes | EnsureRequestId | request_id in all logs |

### Security Headers

| Header | Web | API | Invitation | /up | /ready |
|---|---|---|---|---|---|
| X-Content-Type-Options | nosniff | nosniff | nosniff | nosniff | nosniff |
| Referrer-Policy | strict-origin-when-cross-origin | strict-origin-when-cross-origin | no-referrer | strict-origin | strict-origin |
| X-Frame-Options | SAMEORIGIN | — | — | SAMEORIGIN | SAMEORIGIN |
| Cache-Control | — | no-store | no-store, private | — | — |
| Pragma | — | — | no-cache | — | — |
| HSTS | Configurable | — | — | — | — |

- No middleware conflicts ✓
- No header overwrites ✓
- CSP: **NOT ENABLED** (inline Alpine.js/Tailwind require `unsafe-inline`, defeating purpose) — documented limitation

### Trusted Proxies & Hosts

| Component | Implemented | Tested | Verified |
|---|---|---|---|
| Empty proxies → no trust | Yes | TrustedProxyTest | ✓ |
| Single IP | Yes | TrustedProxyTest | ✓ |
| CIDR ranges | Yes | TrustedProxyTest | ✓ |
| Wildcard `*` not default | Yes | TrustedProxyTest | ✓ |
| Spoofed header ignored (untrusted) | Yes | TrustedProxyTest | ✓ |
| APP_URL host accepted | Yes | TrustedHostTest | ✓ |
| Unknown host rejected | Yes | TrustedHostTest | ✓ |

### CORS

| Setting | Value |
|---|---|
| Paths | `api/*` |
| Origins | From `API_ALLOWED_ORIGINS` (explicit) |
| Credentials | false |
| Allowed headers | Accept, Authorization, Content-Type, X-Request-ID |
| Exposed headers | X-Request-ID |
| `*` origin | NOT allowed |

### Rate Limiters

| Limiter | Target | Limit | Key Strategy |
|---|---|---|---|
| auth | Login, password reset | 5/min | sha256(email|ip) |
| api-public | GET /api/v1/health | 60/min | IP |
| api-authenticated | API endpoints | 120/min | Token ID |
| invitations.manage | Create/resend/cancel | 10/min | User + Company |
| invitations.verify | Invitation page | 20/min | IP |
| invitations.accept | Accept/register | 10/min | IP |
| api-token-management | Create tokens | 10/min | User + Company |
| api-token-management | Revoke tokens | 30/min | User + Company |

- No raw tokens/emails in limiter keys ✓
- No double throttling conflicts ✓
- Proxy-aware (uses `$request->ip()` via trusted proxies) ✓

### Backup & Restore

| Component | Implemented | Tested | Verified |
|---|---|---|---|
| Config | Yes | BackupTest | All settings in .env.example |
| Database backup command | Yes | BackupDetailedTest | gzip artifact verified |
| Files backup command | Yes | BackupDetailedTest | PharData tar.gz verified |
| Verify command | Yes | BackupDetailedTest | Checksum, missing artifact |
| Prune command | Yes | BackupDetailedTest | Dry-run, retention |
| Restore command | Yes | Not rehearsed | **NOT VERIFIED** |
| Restore-verify command | Yes | Not rehearsed | **NOT VERIFIED** |
| Scheduler entries | Yes | SchedulerTest | Daily backup + prune |
| Manifest | Yes | BackupDetailedTest | Version, ID, SHA-256 |
| .my.cnf cleanup | Yes | BackupDetailedTest | No temp files leak |
| Real backup runs | Yes (files only) | Manual | Files backup succeeded locally |
| Real database backup | No | — | mysqldump not available in PATH |
| Isolated restore rehearsal | No | — | **NOT PERFORMED** |

### CI & Deployment

| Component | Implemented | Tested | Verified |
|---|---|---|---|
| ci.yml | Yes | Static + local test | DB_HOST=mysql, PHP 8.4, Node 22 |
| release.yml | Yes | Static + local test | Artifact built with RELEASE.json |
| deploy-production.yml | Yes | Static | SSH security hardened |
| deploy.sh | Yes | DeployScriptTest + rehearsal | Lock, checksum, atomic switch |
| rollback.sh | Yes | DeployScriptTest + rehearsal | Cache rebuild, queue restart |
| Supervisor config | Yes (example) | Not runtime verified | stopwaitsecs=360 aligned |
| Release artifact | Yes | ReleaseBuildTest | SHA-256, RELEASE.json, content verified |
| Temporary deployment rehearsal | Yes | DeploymentRehearsalTest | 10 scenarios verified |
| Atomic switch | Yes | AtomicDeploymentTest | Release lifecycle verified |
| Deploy preflight | Yes | DeployCheckTest + DeployCheckDetailedTest + DeployPreflightTest | 20 checks verified |

---

## 4. Cross-Stage Contradictions

| Contradiction | Stages | Risk | Resolution |
|---|---|---|---|
| Backup manifest uses `database_driver` from config, not actual connection | 9.1, 9.4 | LOW | Metadata field, not functional |
| Queue `retry_after` defaulted to `DB_QUEUE_RETRY_AFTER` fallback | 9.1, 9.2 | LOW | Legacy env name preserved |
| `bootstrap/app.php` uses `env()` directly for TRUSTED_PROXIES/HOSTS | 9.1, 9.3 | NONE | Expected in Laravel 13 bootstrap |
| Supervisor example uses `queue:work redis` but local uses database | 9.2, 9.5 | NONE | Documented as production config |
| `deploy-check` backup freshness defaults to `24h` but scheduler backup is `daily 02:00` | 9.4, 9.5 | LOW | Window covers 24h, documented `BACKUP_MAX_AGE_HOURS` |
| Node version: .nvmrc=22, local=24.15.0, CI=22 | 9.5 | LOW | >=22 compatible, engines field set |
| Release artifact RELEASE.json has `node_version: "22.x"` | 9.5 | NONE | Matches .nvmrc |
| HEALTH_REQUIRE_SCHEDULER=false in .env.example (local) vs =true (production rec) | 9.3 | NONE | Correct: disabled locally, enabled in prod |
| `storage:link` on deploy may fail if storage dir is symlinked shared | 9.5 | LOW | `2>/dev/null \|\| true` handled gracefully |

---

## 5. Test Inventory

| Area | Files | Tests | Coverage |
|---|---|---|---|
| Queue | QueueConfigurationTest, QueueContextTest | 13 | Connection, queue names, context isolation |
| Scheduler | SchedulerTest, SchedulerHeartbeatTest | 11 | Schedule listing, heartbeat freshness |
| Health | HealthCheckTest | 12 | /up, /ready, failure modes, details |
| Request ID | RequestIdTest | 10 | Validation, generation, queue context |
| Security Headers | SecurityHeadersTest, SecurityMiddlewareConflictTest | 13 | All route types, HSTS, no conflicts |
| Trusted Proxies | TrustedProxyTest | 4 | Empty, single, CIDR, wildcard |
| Trusted Hosts | TrustedHostTest | 5 | Allowed, unknown, comma-parsed |
| CORS | CorsTest | 5 | Origin, headers, credentials |
| Rate Limits | RateLimiterTest, RateLimitDetailedTest | 12 | All limiter types, keys, thresholds |
| Logging Redaction | LoggingRedactionTest | 6 | Sensitive field exclusion |
| Session Security | SessionSecurityTest | 6 | HttpOnly, SameSite, secure, JSON |
| Backup | BackupTest, BackupDetailedTest | 14 | Config, commands, manifest, integrity |
| Release Build | ReleaseBuildTest | 8 | RELEASE.json, SHA-256, content |
| Deployment Script | DeployScriptTest | 8 | File existence, nvmrc, shebangs |
| Deploy Check | DeployCheckTest, DeployCheckDetailedTest, DeployPreflightTest | 20 | All production checks |
| Deployment Rehearsal | DeploymentRehearsalTest, AtomicDeploymentTest | 21 | Lock, checksum, switch, rollback, cleanup |
| Environment | EnvironmentExampleTest | 4 | Example file completeness |
| Misc | Scheduler Test, Deploy Script Test, Release Build Test | ~15 | Infrastructure config shapes |

**Total infrastructure tests: ~200** (of 541 total tests)

---

## 6. Failure Scenarios

| Scenario | Expected | Actual | Evidence | Status |
|---|---|---|---|---|
| Database unavailable → /ready | 503 | 503 | HealthCheckTest | ✓ |
| Cache unavailable → /ready | 503 | 503 | HealthCheckTest | ✓ |
| Sync queue + require_async | 503 | 503 | HealthCheckTest | ✓ |
| Stale scheduler heartbeat | 503 | 503 | SchedulerHeartbeatTest | ✓ |
| Missing scheduler heartbeat | 503 | 503 | SchedulerHeartbeatTest | ✓ |
| Invalid request ID | Replaced with UUID | UUID generated | RequestIdTest | ✓ |
| Oversized request ID | Replaced | UUID generated | RequestIdTest | ✓ |
| Untrusted proxy spoofing | Ignored | Client IP preserved | TrustedProxyTest | ✓ |
| Malicious Host header | Rejected | 403 | TrustedHostTest | ✓ |
| CORS unknown origin | Rejected | No Access-Control | CorsTest | ✓ |
| Rate limit exceeded | 429 | 429 | RateLimiterTest | ✓ |
| Backup lock contention | Exit 3 | Exit 3 | BackupDetailedTest | ✓ |
| Backup checksum mismatch | Exit 4 | Exit 4 | BackupDetailedTest | ✓ |
| Deployment checksum mismatch | Stop | Old current retained | DeploymentRehearsalTest | ✓ |
| Deployment lock contention | Rejected | Second deploy blocked | DeploymentRehearsalTest | ✓ |
| Invalid RELEASE.json | Stop | Old current retained | DeploymentRehearsalTest | ✓ |
| Failed cache build | Stop | Release removed | DeploymentRehearsalTest | ✓ |
| Rollback to previous | Switch | Previous restored | DeploymentRehearsalTest | ✓ |
| Deploy APP_KEY missing | Exit 1 | Exit 1 | DeployCheckTest | ✓ |

---

## 7. Production Readiness Matrix

| Component | Implemented | Auto Tests | Local Verification | Staging | Production | Status |
|---|---|---|---|---|---|---|
| Environment config | Yes | Yes | Yes | N/A | N/A | READY |
| Database (MySQL) | Yes | Yes | Yes | N/A | N/A | READY |
| Cache (database→redis) | Yes | Yes | database only | N/A | N/A | READY WITH LIMITATIONS |
| Session (database→redis) | Yes | Yes | database only | N/A | N/A | READY WITH LIMITATIONS |
| Queue (database→redis) | Yes | Yes | database only | N/A | N/A | READY WITH LIMITATIONS |
| Scheduler | Yes | Yes | Yes | N/A | N/A | READY |
| Health endpoints | Yes | Yes | Yes | N/A | N/A | READY |
| Structured logging | Yes | Yes | Yes | N/A | N/A | READY |
| Request ID correlation | Yes | Yes | Yes | N/A | N/A | READY |
| Security headers | Yes | Yes | Yes | N/A | N/A | READY |
| CSP | Not enabled | N/A | Inline scripts block | N/A | N/A | NOT APPLICABLE |
| Trusted proxies | Yes | Yes | Yes | N/A | N/A | READY |
| Trusted hosts | Yes | Yes | Yes | N/A | N/A | READY |
| CORS | Yes | Yes | Yes | N/A | N/A | READY |
| Rate limiting | Yes | Yes | database store | N/A | N/A | READY WITH LIMITATIONS |
| Backup (files) | Yes | Yes | Yes | N/A | N/A | READY |
| Backup (database) | Yes | Partial | mysqldump N/A | N/A | N/A | NOT VERIFIED |
| Restore | Yes | No | Not rehearsed | N/A | N/A | NOT VERIFIED |
| Disaster recovery | Partial | No | Not rehearsed | N/A | N/A | NOT VERIFIED |
| CI workflow | Yes | Static | Locally tested | N/A | N/A | READY |
| Release build | Yes | Static | Artifact created | N/A | N/A | READY |
| Deployment | Yes | Rehearsal | Temp fixture | N/A | N/A | READY WITH LIMITATIONS |
| Rollback | Yes | Rehearsal | Temp fixture | N/A | N/A | READY WITH LIMITATIONS |
| Supervisor | Yes (example) | No | Not runtime verified | N/A | N/A | NOT VERIFIED |
| Cron | Documented | No | N/A | N/A | N/A | NOT VERIFIED |

---

## 8. Risk Register

| # | Risk | Severity | Impact | Mitigation |
|---|---|---|---|---|
| 1 | Restore never rehearsed | HIGH | DR readiness claim invalid | Perform isolated restore rehearsal before production |
| 2 | Database backup not verified locally (no mysqldump) | MEDIUM | Unknown backup integrity for database artifacts | Verify in CI or staging with real MySQL |
| 3 | Redis runtime behavior unconfirmed | MEDIUM | Cache/session/queue behavior differs | Test with Redis in staging before production |
| 4 | Production SSH fingerprint unverified | HIGH | MITM risk on deploy | Set DEPLOY_HOST_KEY, verify fingerprint manually |
| 5 | Supervisor worker health not monitored | MEDIUM | Workers may silently stop | Add worker heartbeat or external monitoring |
| 6 | No CSP enforced | LOW | XSS hardening absent | Documented limitation (inline scripts) |
| 7 | Shared views/cache on rollback | LOW | Cached views from old release | Rollback rebuilds caches (verified) |
| 8 | `storage:link` race on deploy | LOW | Public storage symlink | `2>/dev/null \|\| true` handles gracefully |
| 9 | Rate limits use database cache locally | LOW | Not distributed | Production uses Redis (shared) |
| 10 | Scheduler cron not production-verified | LOW | Missed tasks if cron not set | Documented as required, deploy checklist |
| 11 | Node 24.15.0 local vs Node 22 CI | LOW | Possible subtle runtime differences | >=22 compatible, npm ci passes on both |
| 12 | `mv -T` in deploy.sh Linux-specific | LOW | Won't work on BSD/macOS | Documented Linux requirement |

---

## 9. Not Verified

| Component | Reason | Environment Needed |
|---|---|---|
| Redis runtime (all drivers) | Redis not installed locally | Local Redis or staging |
| Database backup | mysqldump not in PATH | MySQL installation or CI |
| Isolated restore rehearsal | Requires dedicated test DB | Staging or CI with MySQL |
| Disaster recovery flow | Depends on restore verification | Staging or production rehearsal |
| GitHub Actions runtime | Windows local (no runner) | GitHub or `act` |
| Production SSH deployment | No production server | Production environment |
| Supervisor process health | No supervisor installed | Production server |
| Production cron | No production server | Production server |
| Production `/up` `/ready` | No production server | Production environment |
| ShellCheck (not installed) | Tool not available | Linux or WSL |
| actionlint (not installed) | Tool not available | Any environment |

---

## 10. Commands Executed

| Command | Exit Code | Result |
|---|---|---|
| `composer validate --strict` | 0 | Valid |
| `php artisan test` | 0 | 541/541 passed, 3 risky |
| `./vendor/bin/pint --test` | 0 | PASS |
| `./vendor/bin/phpstan analyse` | 0 | 0 errors |
| `npm run build` | 0 | 4 modules built |
| `php artisan config:cache` | 0 | Cached |
| `php artisan route:cache` | 0 | Cached |
| `php artisan view:cache` | 0 | Cached |
| `php artisan route:list` | 0 | 47 routes |
| `php artisan schedule:list` | 0 | 7 tasks |
| `php artisan schedule:run -v` | 0 | Heartbeat ran |
| `php artisan queue:failed` | 0 | No failed jobs |
| `php artisan nordipass:deploy-check` | 0 | 15 passed |
| `php artisan nordipass:backup --dry-run` | 0 | Config table shown |
| `php artisan nordipass:backup-prune --dry-run` | 0 | Dry run complete |
| `php artisan nordipass:backup --files-only` | 0 | Backup succeeded (test fixture) |
| `bash -n deploy/scripts/deploy.sh` | 0 | Syntax OK |
| `bash -n deploy/scripts/rollback.sh` | 0 | Syntax OK |
| `git diff --check` | 0 | Clean |

---

## 11. Final Verdict

### INFRASTRUCTURE VERIFIED WITH LIMITATIONS

**Rationale:**
- No BLOCKER risks
- All automated tests pass (541/541)
- All configs valid, environment consistent
- Cross-stage contradictions resolved or documented as intentional
- Security headers, rate limits, trusted proxies/hosts, CORS fully verified
- Queue/scheduler timeouts matrix aligned (`retry_after > worker timeout`)
- Deployment/rollback rehearsal verified locally
- CI/deployment workflows statically validated and hardened
- Health endpoints (liveness + readiness) fully verified

**Accepted Limitations:**
- Redis runtime not verified (not installed locally) → staging verification required
- Database backup not verified (no mysqldump) → CI or staging verification required
- Restore rehearsal not performed → Stage 9.4 marked incomplete for restore
- Production SSH, Supervisor, cron → require actual production environment
- CSP not enabled → documented limitation (inline scripts)

**Pre-production actions required:**
1. Verify Redis in staging
2. Run full database backup + verify + isolated restore rehearsal
3. Verify SSH host key fingerprint
4. Configure production Supervisor + cron
5. Run deployment to staging
6. Smoke test `/up`, `/ready` on production

---

*This document supersedes all previous infrastructure verification claims.*
