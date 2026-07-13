# Health, Logging & Security Baseline

## Overview

Stage 9.3 provides the baseline for production health monitoring, structured logging, and security hardening for NordiPass.

## Health Endpoints

### `GET /up` — Liveness

Laravel's built-in health route. Returns `200` when the application process has booted successfully.

- No database queries
- No Redis checks
- No authentication
- No session
- No tenant context

### `GET /ready` — Readiness

Separate endpoint that verifies critical dependencies:

| Check | Status | Optional? |
|-------|--------|-----------|
| Database | `SELECT 1` | No (default) |
| Cache | Write/read/forget cycle | No (default) |
| Queue backend | Connection validation | No (default) |
| Scheduler heartbeat | Age check | Yes (default: off) |

Response format:

```json
{
  "status": "ok",
  "timestamp": "2026-07-13T18:00:00Z"
}
```

When `HEALTH_DETAILS=true`:

```json
{
  "status": "ok",
  "checks": {
    "database": "ok",
    "cache": "ok",
    "queue": "ok",
    "scheduler": "ok"
  },
  "timestamp": "2026-07-13T18:00:00Z"
}
```

Returns `200` when all required checks pass, `503` when a required dependency is unavailable.

### Health limitations

- Queue backend check verifies **connectivity only**, not worker process health
- Scheduler check requires configuration (`HEALTH_REQUIRE_SCHEDULER=true`)
- No external monitoring integration in R0

## Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `HEALTH_ENABLED` | `true` | Enable readiness checks |
| `HEALTH_DETAILS` | `false` | Include check details in response |
| `HEALTH_REQUIRE_DATABASE` | `true` | Require database for ready status |
| `HEALTH_REQUIRE_CACHE` | `true` | Require cache for ready status |
| `HEALTH_REQUIRE_QUEUE` | `true` | Require queue backend for ready status |
| `HEALTH_REQUIRE_SCHEDULER` | `false` | Require scheduler heartbeat for ready status |
| `HEALTH_SCHEDULER_MAX_AGE` | `180` | Maximum age of scheduler heartbeat (seconds) |
| `HEALTH_REQUIRE_ASYNC_QUEUE` | `false` | Reject sync queue as production ready |

## Scheduler Heartbeat

The `nordipass:scheduler-heartbeat` command writes a UTC timestamp to the cache every minute:

```bash
php artisan nordipass:scheduler-heartbeat
```

Cache key: `nordipass:infrastructure:scheduler:last_run`

When `HEALTH_REQUIRE_SCHEDULER=true`, readiness checks that the heartbeat is fresher than `HEALTH_SCHEDULER_MAX_AGE` seconds.

## Structured Logging

### Local (readable)

```env
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug
```

### Production JSON (container/stderr)

```env
LOG_CHANNEL=stack
LOG_STACK=stderr_json
LOG_LEVEL=info
```

### Production JSON (file)

```env
LOG_CHANNEL=stack
LOG_STACK=daily_json
LOG_LEVEL=info
```

### Log channels

| Channel | Format | Retention |
|---------|--------|-----------|
| `single` | Plain text | N/A |
| `daily` | Plain text | 14 days |
| `daily_json` | JSON lines | 14 days |
| `stderr_json` | JSON lines | N/A |

### Log context fields

Structured logs include:

- `request_id` — from X-Request-ID header or auto-generated UUID
- `user_id` — when authenticated
- `company_id` — in tenant context
- `route_name` — matched route name
- `http_method` — request method

### Sensitive data rules

The following are **never** included in logs:

- Authorization header
- Cookie header
- Password fields
- API tokens
- Invitation tokens
- Raw invitation URLs
- Full request body
- Database credentials
- Application key

## Request ID

The `X-Request-ID` header is managed by `EnsureRequestId` middleware:

1. Incoming `X-Request-ID` accepted only if valid (1–100 alphanumeric/dot/dash/underscore chars)
2. Invalid or missing IDs are replaced with a new UUIDv4
3. The ID is stored in Laravel Context, log context, and response header
4. Queue jobs receive the correlation context automatically

### Queue correlation

Laravel Context serializes the `request_id` across queue boundaries automatically. The context is scoped per job and does not leak between jobs.

### Queue context safety

- No request body
- No authorization headers
- No API tokens
- No email addresses
- Only technical correlation metadata

## Security Headers

| Header | Value | Scope |
|--------|-------|-------|
| `X-Content-Type-Options` | `nosniff` | All responses |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | All responses |
| `X-Frame-Options` | `SAMEORIGIN` | All responses |
| `Cache-Control` | `no-store, private` | Invitation pages |
| `Pragma` | `no-cache` | Invitation pages |
| `Referrer-Policy` | `no-referrer` | Invitation pages |
| `Strict-Transport-Security` | Configurable | Production HTTPS only |

API responses additionally include `Cache-Control: no-store`.

### Content Security Policy

CSP is not enforced in R0. The current frontend uses inline scripts (Alpine.js) and inline styles (Tailwind) that would require `'unsafe-inline'` in a CSP policy, reducing its effectiveness. CSP can be reviewed when the frontend is updated to use nonce-based or hash-based policies.

### HSTS

| Variable | Default | Description |
|----------|---------|-------------|
| `SECURITY_HSTS_ENABLED` | `false` | Enable HSTS header |
| `SECURITY_HSTS_MAX_AGE` | `31536000` | max-age in seconds |
| `SECURITY_HSTS_INCLUDE_SUBDOMAINS` | `false` | includeSubDomains directive |
| `SECURITY_HSTS_PRELOAD` | `false` | preload directive |

HSTS is only sent when:
- `SECURITY_HSTS_ENABLED=true`
- Application environment is `production`
- Request uses HTTPS (detected via trusted proxies)

## Trusted Proxies

Configure via `TRUSTED_PROXIES`:

```env
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
```

Multiple values are comma-separated. `TRUSTED_PROXIES=*` accepts all (use only when all traffic passes through a controlled proxy).

Trusted proxies affect:
- Request IP detection
- HTTPS detection (`$request->secure()`)
- HSTS activation
- Rate limiter IP keys

## Trusted Hosts

Configure via `TRUSTED_HOSTS`:

```env
TRUSTED_HOSTS=localhost,127.0.0.1,nordipass.test,example.com
```

Requests with unknown `Host` headers are rejected with `403`. This prevents host header injection attacks.

## CORS

| Setting | Value |
|---------|-------|
| Allowed paths | `api/*` |
| Allowed methods | All |
| Allowed origins | From `API_ALLOWED_ORIGINS` (configurable) |
| Allowed headers | `Accept`, `Authorization`, `Content-Type`, `X-Request-ID` |
| Exposed headers | `X-Request-ID` |
| Credentials | `false` (bearer token API) |

### Session cookies

| Setting | Local | Production |
|---------|-------|------------|
| `SESSION_SECURE_COOKIE` | `false` | `true` |
| `SESSION_HTTP_ONLY` | `true` | `true` |
| `SESSION_SAME_SITE` | `lax` | `lax` |

## Rate Limiters

| Limiter | Routes | Limit | Key |
|---------|--------|-------|-----|
| `auth` | Login, password reset | 5/min | sha256(email\|ip) |
| `api-public` | `GET /api/v1/health` | 60/min | IP |
| `api-authenticated` | API endpoints | 120/min | Token ID |
| `invitations.manage` | Invite, resend, cancel | 10/min | User + Company |
| `invitations.verify` | Invitation show page | 20/min | IP |
| `invitations.accept` | Accept/register | 10/min | IP |
| `api-token-management` | Create/revoke tokens | 10 create, 30 revoke/min | User + Company |

### Distributed rate limits

Rate limiting uses the application cache store. Distributed rate limits require a shared cache (Redis in production). Local testing uses database cache which is single-node only.

## Production checklist

- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production`
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `TRUSTED_PROXIES` configured
- [ ] `TRUSTED_HOSTS` contains production domains
- [ ] `LOG_LEVEL=warning`
- [ ] Redis configured for cache/queue/session
- [ ] `HEALTH_REQUIRE_SCHEDULER=true`
- [ ] `HEALTH_REQUIRE_ASYNC_QUEUE=true`
- [ ] `SECURITY_HSTS_ENABLED=true` (after HTTPS confirmed)
- [ ] `API_ALLOWED_ORIGINS` set to production origins
- [ ] Scheduler cron entry configured
- [ ] Queue worker running via Supervisor
