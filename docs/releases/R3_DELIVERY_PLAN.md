# R3 — Delivery Plan and Implementation Order

## Status: R3.1 Architecture Lock — APPROVED

---

## 1. Recommended Implementation Order

| Order | Stage | Name | Rationale |
|-------|-------|------|-----------|
| 1 | R3.1 | Architecture and Scope Lock | Foundation (current) |
| 2 | R3.2 | Readiness Profiles v2 and UX | Data model contract for R3.3, R3.4, R3.18 |
| 3 | R3.3 | Advanced DPP Sections | Data model contract for R3.11, R3.13, R3.18 |
| 4 | R3.4 | Documents and Compliance Workflow | Contract for R3.11, R3.14 |
| 5 | R3.5 | Taxonomy and Attribute Governance | Contract for R3.2 profile selection, R3.6, R3.7 |
| 6 | R3.6 | Import/Export Production Upgrade | Contract for R3.18 |
| 7 | R3.7 | Fortnox Integration | Contract for R3.14, R3.15 |
| 8 | R3.8 | Billing, Plans and Entitlements | Contract for commercial entitlements, R3.18 |
| 9 | R3.9 | QR and Product Analytics | Contract for R3.11 instrumentation, R3.15 |
| 10 | R3.10 | Team Roles and Approval Workflow | Contract for publication, compliance, API permissions |
| 11 | R3.11 | Public Passport v2 | Contract for R3.12, R3.18 |
| 12 | R3.12 | QR and Label Management | Builds on R3.11 |
| 13 | R3.13 | Public API and Webhooks | Stable contracts from R3.2-R3.11 |
| 14 | R3.14 | Notifications and Job Center | Events from documents, imports, Fortnox, billing, publishing |
| 15 | R3.15 | Platform Operations | R3.17 operational telemetry |
| 16 | R3.16 | Security and Privacy Hardening | All modules |
| 17 | R3.17 | Performance and Observability | R3.19 prerequisite |
| 18 | R3.18 | Public Beta Onboarding | Completed commercial journey |
| 19 | R3.19 | R3 Final Acceptance Verification | All mandatory R3 stages |

---

## 2. Parallelization Strategy

### Group A — Foundation (Sequential)
- R3.1 → R3.2 → R3.3 → R3.4 → R3.5

Rationale: Each stage establishes data contracts for subsequent stages.

### Group B — Independent Features (Can parallel after Group A)
- R3.6 (Import/Export)
- R3.7 (Fortnox)
- R3.8 (Billing)

Can begin in parallel after R3.5 data contracts are stabilized.

### Group C — UX and Delivery (Can begin after R3.3-R3.4)
- R3.9 (Analytics)
- R3.10 (Roles and Approval)
- R3.11 (Public Passport v2)
- R3.12 (QR and Labels)

### Group D — Integration Layer (Can begin after Group A-C data contracts)
- R3.13 (Public API and Webhooks)
- R3.14 (Notifications)

### Group E — Operations and Hardening (Can overlap with Group D)
- R3.15 (Platform Operations)
- R3.16 (Security and Privacy)
- R3.17 (Performance and Observability)

### Group F — Final (Sequential after all)
- R3.18 (Onboarding)
- R3.19 (Final Acceptance)

---

## 3. Contract Gate

Before parallel implementation begins, the following shared contracts MUST be stabilized:

| Contract | Established In | Consumed By |
|----------|---------------|-------------|
| Readiness profile v2 schema | R3.2 | R3.5, R3.14, R3.18 |
| DPP section schema v2 | R3.3 | R3.11, R3.13, R3.18 |
| Document compliance model | R3.4 | R3.11, R3.14, R3.18 |
| Event payload contracts | R3.2-R3.11 | R3.13, R3.14 |
| API versioning policy | R3.1 (this doc) | R3.13 |
| Feature flag registration | R3.1 (this doc) | All stages |

---

## 4. Stage Completion Evidence

Each stage R3.2-R3.19 must produce:

| Evidence | Description |
|----------|-------------|
| Implementation | Code committed and reviewed |
| Tests | Full test coverage (unit, feature, MySQL integration, E2E where applicable) |
| Documentation | Updated domain docs, API docs, ADRs |
| Migration | Database migrations tested on fresh install AND R2 upgrade |
| Pint clean | `vendor/bin/pint --test` passes |
| PHPStan clean | `vendor/bin/phpstan analyse` passes (level from phpstan.neon) |
| Build clean | `npm run build` passes |
| Security | Cross-tenant tests, authorization tests, no regressions |
| Acceptance | Stage acceptance criteria met per contract |

The implementation-ready contract for every stage is fixed in `docs/releases/R3_IMPLEMENTATION_CONTRACTS.md`.

---

## 5. Timeline Guidance (Advisory)

Sequential ordering assumes:
- R3.2-R3.5: foundational data model changes (~2-3 units each)
- R3.6-R3.8: integration-heavy features (~3-4 units each)
- R3.9-R3.12: UX and delivery (~2-3 units each)
- R3.13-R3.15: infrastructure (~2 units each)
- R3.16-R3.17: hardening (~1-2 units each)
- R3.18: onboarding (~1 unit)
- R3.19: verification (~1 unit)
