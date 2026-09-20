# Runbook: Incident Response

Status: R3.16 deliverable. Owner: on-call operator with `platform.access` role.

## Classification

| Severity | Definition | Response time |
|----------|-----------|----------------|
| Critical | Cross-tenant data exposure, secret leakage, public passport serving wrong-tenant data, data loss | Immediate escalation, page the owner |
| High | Authentication/authorization bypass in a single tenant, active abuse campaign | Same business day |
| Medium | Degraded service, failed-job backlog, single-tenant outage | Next business day |
| Low | Cosmetic, non-blocking | Next sprint |

## Immediate Response

1. **Triage** — reproduce from the report; identify affected tenants via platform operations (`/platform/operations`).
2. **Contain** — for suspected cross-tenant access or secret leakage: revoke affected API tokens (`settings/api-tokens`), disconnect Fortnox connections, or suspend the user via `UserStatus::Suspended`.
3. **Isolate** — for a compromised tenant, suspend the company (`CompanyStatus::Suspended`) to block all tenant access without deleting data.
4. **Preserve** — do not delete audit rows. Audit logs are immutable by design; export relevant `log_name = platform` rows as evidence.

## Investigation

- Start from `audit` events (`auth.login_failed`, `member.*`, `platform.*`) and API token `last_used_at`.
- Check `platform_abuse_events` and `platform_incidents`; record findings as an incident (`POST /platform/operations/.../incidents`).
- For secret exposure: run gitleaks locally (`gitleaks detect`) and check CI history.

## Recovery

1. Rotate the compromised secret (see Token Rotation runbook).
2. Restore tenant state from backup if data was altered (see Backup Restoration runbook).
3. Re-enable access only after verification.

## Communication

- Inform affected customers within the breach-response SLA (see Data Breach Response runbook).
- Post-incident: write a timeline into `platform_incidents` and close with `resolved_at`.
