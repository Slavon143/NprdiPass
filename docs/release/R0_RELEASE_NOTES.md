# NordiPass R0 — Release Notes

**Release:** R0 Foundation  
**Date:** 2026-07-13  
**Status:** RELEASE READY WITH ACCEPTED LIMITATIONS  

---

## R0 Scope

R0 delivers the complete application foundation for NordiPass. Product modules (Catalog, Documents, QR, DPP, Billing, Integrations, AI/RAG) are intentionally deferred to R1.

### Implemented capabilities

**Foundation (Stages 1–3)**
- Laravel 13 application bootstrap with Vite + Tailwind
- Company model as shared-schema tenant
- Company memberships with role system (owner, admin, editor, viewer)
- Session-based current company selection
- Company switching via UUID with fresh membership verification
- Tenant middleware chain: auth → verified → company.resolve → company.selected → company.member → company.active

**Authorization (Stage 4)**
- Dual-level authorization: platform (Spatie) + company roles
- CompanyPermission enum + CompanyPermissionMatrix
- CompanyAuthorizer with named Gates
- Policies: CompanyPolicy, CompanyMemberPolicy, CompanyInvitationPolicy, AuditLogPolicy
- Owner-safety: admin cannot manage owners, last owner cannot be removed
- All role changes and member removals use transactional actions with row locks

**Company UI + Invitations (Stages 5–6)**
- Dashboard with real foundation data
- Company settings (name, legal name, org number, country, billing email)
- Members page with role-based management
- Invitation flow: create, resend, cancel, accept, expire
- Cryptographically secure token generation (48 random bytes)
- SHA-256 token hash storage only
- Row lock + 23000 constraint race protection
- Queued email notifications (afterCommit, mail queue)
- Invitation expiry: 72 hours default
- Pruning via daily scheduler

**Audit (Stage 7)**
- Spatie activitylog with custom immutable AuditLog model
- Explicit security events (login, logout, company changes, invitations, API tokens)
- Tenant-scoped with validated company_id
- SensitiveDataSanitizer: no passwords, tokens, or credentials in audit
- X-Request-ID correlation
- Retention: 365 days, daily pruning

**API Foundation (Stage 8)**
- Laravel Sanctum personal access tokens
- API v1 endpoints: GET /health (public), GET /me, GET /company, GET /company/members
- Token abilities: company.read, members.read (no wildcards)
- Company-scoped tokens via company_id
- Token expiration: 90 days default, 365 days maximum
- Non-expiring tokens disabled by default
- Structured JSON responses with request ID, stable error codes
- Rate limits: 120/min per token, 60/min per IP for health
- CORS with explicit API_ALLOWED_ORIGINS

**Infrastructure (Stage 9)**
- 3 queue names: mail, maintenance, default
- 7 scheduled tasks (3 pruning, heartbeat, backup, backup-prune, failed-job-prune)
- Health: GET /up (liveness) + GET /ready (readiness with DB/cache/queue/scheduler checks)
- Structured JSON logging for production
- Security headers: X-Content-Type-Options, Referrer-Policy, X-Frame-Options, HSTS
- Trusted proxies/hosts with comma-separated configuration
- Rate limiters for auth, API, invitations, token management
- MySQL backup with mysqldump (single-transaction, gzip)
- Isolated database restore verified (10/10 checks)
- Files backup via PharData
- Backup manifests with SHA-256 integrity
- Backup retention: 7 daily, 4 weekly, 3 monthly
- CI: GitHub Actions (PHP 8.4, MySQL 8.0, Node 22)
- Release artifact build with RELEASE.json + SHA-256
- Deployment: atomic current symlink, flock, health polling, rollback
- Supervisor worker config example

---

## Known Limitations

| Limitation | Severity | Detail |
|---|---|---|
| Production Redis not verified | MEDIUM | Redis not installed locally; cache/session/queue use database driver |
| Production deployment not verified | HIGH | No staging/production server available |
| Offsite backup not configured | HIGH | Local disk only (S3/SFTP required for production) |
| CSP not enforced | LOW | Inline Alpine.js/Tailwind scripts require unsafe-inline |
| PHP startup warnings | LOW | Duplicate module loading (Herd + Scoop PHP configurations) |
| 3 risky tests | LOW | Pre-existing minor infrastructure checks without assertions |

---

## Operational Requirements

### Environment
- PHP 8.4 with pdo_mysql, fileinfo
- MySQL 8 or MariaDB 10+
- Node.js 22 LTS (>=22 compatible)
- Composer 2

### Production
- Redis for cache, session, queue
- Trusted SMTP for invitation emails (do not use log mailer)
- Supervisor for queue workers
- Cron: `* * * * * cd /path/to/nordipass/current && php artisan schedule:run`
- `current/artisan` symlink (not specific release path)
- Secure .env permissions (chmod 600)
- Trusted proxy configuration for reverse proxy
- HSTS enabled after HTTPS verified

### Security
- APP_DEBUG=false in production
- SESSION_SECURE_COOKIE=true in production
- HEALTH_REQUIRE_SCHEDULER=true in production
- HEALTH_REQUIRE_ASYNC_QUEUE=true in production
- API_ALLOWED_ORIGINS set to explicit production origins
- DEPLOY_HOST_KEY configured for SSH deployment
- Strict host key verification (never StrictHostKeyChecking=no)

---

## Deferred to R1

- Product Catalog (products, variants, categories)
- Documents management
- QR code generation
- Digital Product Passport (DPP)
- Billing and subscriptions
- Fortnox integration
- Excel import/export
- AI/RAG features
- Search and analytics
- Custom domains
- Mobile applications
- Content Security Policy (CSP) enforcement
