# ADR-R3-010 — Feature Flags and Rollout Strategy

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None (new infrastructure capability)

---

## Context

R2 has no feature flag infrastructure. All capabilities are unconditionally enabled. R3 introduces multiple independent features that should be selectively enabled during development, testing, and phased rollout.

## Decision

### Feature Flag Infrastructure

- Configuration-based flags in `config/features.php`
- Database overrides in `feature_flags` table for tenant-specific rollout
- Application-level check: `Features::active('flag_name', $company)`

### Flag Inventory

| Flag | Default | Dependencies | Purpose |
|------|---------|-------------|---------|
| `readiness_profiles_v2` | off | R3.2 | Enable versioned readiness profiles |
| `advanced_dpp_sections` | off | R3.3 | Enable new DPP sections |
| `compliance_workflow` | off | R3.4 | Enable document review/approval |
| `taxonomy_governance` | off | R3.5 | Enable category templates |
| `production_imports` | off | R3.6 | Enable Excel/CSV import |
| `fortnox_integration` | off | R3.7 | Enable Fortnox integration |
| `billing` | off | R3.8 | Enable Stripe billing |
| `analytics` | off | R3.9 | Enable analytics collection |
| `approval_workflow` | off | R3.10 | Enable maker-checker approval |
| `public_passport_v2` | off | R3.11 | Enable new public passport UI |
| `qr_labels` | off | R3.12 | Enable QR label management |
| `public_api_extensions` | off | R3.13 | Enable new API endpoints |
| `notifications_center` | off | R3.14 | Enable in-app notifications |
| `platform_operations` | off | R3.15 | Enable support/ops tools |

### Security Flags (Cannot Be Disabled)
Security controls are NOT behind ordinary feature flags:
- Authentication/authorization
- Rate limiting
- CSRF protection
- Security headers
- Tenant isolation
- Input validation
- Audit logging

These are always enforced regardless of feature flag state.

### Rollout Strategy

| Phase | Scope | Duration |
|-------|-------|----------|
| Internal testing | NordiPass team only | Per-stage |
| Alpha | Selected beta testers (manual flag enable per company) | Per-group |
| Beta | All new companies (flag on by default for new) | 1-2 weeks |
| GA | All companies (flag on for all, config default = on) | After R3.19 |

### Rollback
- Each flag can be toggled off in config
- Feature must be safe to disable: no data loss, no broken references
- Disabled feature returns appropriate HTTP status (404 or 403) rather than 500
- Feature-created data remains but is hidden when flag is off

### Removal
- Flags removed 2 releases after GA when feature is stable
- Removal date documented in flag config
- No code path remains that checks removed flags

## Alternatives Considered

1. **Environment-only flags (.env)**: Rejected — cannot roll out per-tenant. .env flags require deploy, unsuitable for gradual rollout.
2. **Database-only flags**: Rejected — no default for new environments. Config-based with DB overrides gives safe defaults.

## Consequences

- `config/features.php` created as new config file
- `feature_flags` migration added (company_id, flag_name, enabled, timestamps)
- Application-wide `Features` facade/service for flag checks
- Each R3 stage must wrap new functionality behind its flag
- CI tests must run with flags both on and off
