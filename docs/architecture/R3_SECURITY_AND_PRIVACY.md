# R3 — Security and Privacy Gates

## Status: R3.1 Architecture Lock — APPROVED

---

## 1. Security Architecture Principles

1. **Defense in depth**: Authentication → Authorization → Tenant Isolation → Validation → Audit
2. **Zero trust between tenants**: All queries tenant-scoped, all foreign keys composite
3. **Immutable audit**: Audit trail must survive all operations, including deletion
4. **Secure by default**: New features default to most restrictive access
5. **Privacy by design**: Minimize PII collection, maximize user control

---

## 2. Per-Stage Security Requirements

| Stage | Authorization | Tenant Isolation | CSRF/XSS | File Security | OAuth/API | Special |
|-------|:---:|:---:|:---:|:---:|:---:|-------|
| R3.2 | Policy check on profile assignment | Company-scoped profile assignments | Standard | — | — | — |
| R3.3 | Policy check on DPP section access | DPP data already tenant-scoped | Standard | — | — | — |
| R3.4 | New `ComplianceManager` role | Document review company-scoped | Standard | PDF validation hardening | — | Certificate metadata integrity |
| R3.5 | Admin-only template management | Templates company-scoped | Standard | — | — | — |
| R3.6 | Policy check on import | Import company-scoped | Standard | File upload validation (Excel/CSV) | — | Chunked upload security |
| R3.7 | Company admin manages Fortnox | OAuth token company-scoped | — | — | OAuth state param, token encryption, CSRF on callback | SSRF prevention on Fortnox API calls |
| R3.8 | Policy check on billing access | Subscription company-scoped | — | — | Stripe webhook signature verification, replay protection | Payment data never touches our server |
| R3.9 | Analytics only for passport owner | Analytics data company-scoped | — | — | — | PII minimization, bot filtering |
| R3.10 | New roles + granular permissions | Approval requests company-scoped | Standard | — | — | Role escalation prevention |
| R3.11 | Public — no auth | Public data from snapshot | XSS in Blade escaping, CSP | — | Rate limiting | — |
| R3.12 | Policy check on QR generation | Company-scoped | SVG sanitization | SVG/PNG/PDF generation safety | — | — |
| R3.13 | API token abilities | API tenant-scoped via token | — | — | Webhook signature generation, replay protection, idempotency | SSRF on outbound webhooks |
| R3.14 | User preferences scoped | Notifications company-scoped | Standard | — | — | Email preference enforcement |
| R3.15 | Restricted support access | Support cross-tenant with audit | Standard | — | — | Support access logging, admin audit |
| R3.16 | GDPR workflows | Cross-tenant data export isolation | CSP enforcement | Upload hardening, MIME type enforcement | — | Dependency scanning, secret detection |
| R3.17 | — | — | — | — | — | Log PII redaction, metric privacy |
| R3.18 | Standard | Company-scoped onboarding | Standard | — | — | Demo data isolation |
| R3.19 | Full permission matrix review | Cross-tenant negative tests | Full coverage | Full coverage | Full coverage | Security runbook verification |

---

## 3. Critical Threat Zones

### Fortnox OAuth (R3.7)
| Threat | Control |
|--------|---------|
| OAuth state forgery | State parameter with HMAC, verified on callback |
| Token theft | Encrypted at rest, never logged |
| CSRF on callback | `state` parameter, standard CSRF protection |
| SSRF on API calls | Fortnox API base URL allowlist, internal network block |
| Token reuse | Token bound to company, validated on each request |

### Stripe Webhooks (R3.8)
| Threat | Control |
|--------|---------|
| Forged webhook | Signature verification with Stripe signing secret |
| Replay attack | Idempotency via `stripe_event_id` |
| Missing webhook | Reconciliation job compares to Stripe API |
| Secret leakage | Stripe keys in `.env` only, never logged |

### Outbound Webhooks (R3.13)
| Threat | Control |
|--------|---------|
| SSRF | Webhook URL validation (no internal IPs, no localhost) |
| Delivery to wrong endpoint | URL registered per company, verified on creation |
| Signature forgery | HMAC-SHA256 signature generation |
| Replay by receiver | Timestamp in signature (tolerance ±5 min) |
| Secret leakage | Webhook secret encrypted at rest |

### Public API (R3.13)
| Threat | Control |
|--------|---------|
| Unauthorized access | Token abilities checked per endpoint |
| Rate limit bypass | Token-based rate limiting |
| Tenant isolation bypass | Company resolved from token, 404 for wrong tenant |
| Mass assignment | Form requests with `->validated()` only |

### File Uploads (R3.4, R3.6)
| Threat | Control |
|--------|---------|
| Malicious file upload | MIME type detection (finfo), header validation, extension allowlist |
| Path traversal | `MediaPathGuard`, storage keys NOT from user input |
| SVG XSS | SVG sanitizer, no inline scripts |
| PDF exploits | PDF validation, size limits, file header check |
| Excessive upload | Rate limiting, file size limits |

### Support Access (R3.15)
| Threat | Control |
|--------|---------|
| Unauthorized company access | Restricted support role, explicit access grant |
| Access without audit | Every support access logged in activity_log |
| Privilege escalation | Support role cannot manage members or billing |

---

## 4. Privacy Gate Checklist

| Requirement | Stage | Status |
|-------------|-------|--------|
| No raw IP storage in analytics | R3.9 | Design requirement |
| No cookies on public passport | R3.11 | Design requirement |
| GDPR export workflow | R3.16 | Implementation requirement |
| GDPR deletion workflow | R3.16 | Implementation requirement |
| Data retention policy enforced | R3.16 | Implementation requirement |
| DPA documentation | R3.16 | Documentation requirement |
| Processor inventory | R3.16 | Documentation requirement |
| Consent for marketing emails | R3.14 | Design requirement |
| Public analytics privacy notice | R3.11 | Documentation requirement |

---

## 5. Dependency Scanning

| Scan Type | Tool | Frequency |
|-----------|------|-----------|
| PHP dependencies | `composer audit` | CI on every push |
| JS dependencies | `npm audit` | CI on every push |
| Secret detection | Git hooks + CI | Pre-commit + CI |
| Static analysis | PHPStan (level from phpstan.neon) | CI on every push |

---

## 6. Security Runbooks (R3.16 Deliverable)

| Runbook | Purpose |
|---------|---------|
| Incident response | Security incident classification, escalation, communication |
| Token rotation | API token compromise response |
| Data breach response | GDPR notification procedure |
| Backup restoration | Verified restore from backup |
| OAuth token revocation | Fortnox/Stripe disconnection procedure |
