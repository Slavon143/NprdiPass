# ADR-R3-001 — Public Beta Scope and Non-Goals

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None (initial R3 baseline)

---

## Context

R2 (NordiPass Pilot) has been formally accepted. The project now enters R3 (Public Beta). Before any functional implementation begins, the R3 scope must be locked, non-goals must be explicitly defined, and the architecture baseline must be fixed.

## Decision

### Scope Lock
The R3 scope is defined by 18 functional stages (R3.2 through R3.19) covering:
- Readiness Profiles v2
- Advanced DPP sections
- Documents and compliance workflow
- Taxonomy and attribute governance
- Production import/export
- Fortnox integration
- Stripe billing and entitlements
- Analytics
- Team roles and approval workflow
- Public Passport v2
- QR and label management
- Public API and webhooks
- Notifications and job center
- Platform operations
- Security and privacy hardening
- Performance and observability
- Public Beta onboarding
- Final acceptance verification

### Non-Goals
The following are explicitly excluded from R3:
- Production AI/RAG assistant (R4+)
- Automated legal certification (R5+)
- Enterprise SSO/SAML/SCIM (R4+)
- Multi-region active-active deployment (R5+)
- Arbitrary workflow builder (R4+)
- Integration marketplace (R5+)
- Universal taxonomy migration engine (R4+)
- Native mobile applications (Post-Public Beta)
- Custom payment engine outside Stripe (R4+)

### Architecture Baseline
R2 accepted code (commit `c9f0794`) is the source of truth. Any contradiction between documentation and code is resolved in favor of the R2 accepted implementation.

## Alternatives Considered

1. **Open scope (decide during implementation)**: Rejected — would lead to scope creep, missed dependencies, and architectural contradictions.
2. **Include SSO in R3**: Rejected — SSO requires enterprise identity provider integration testing that would delay Public Beta.
3. **Include AI assistant in R3**: Rejected — AI requires separate data governance, model selection, and accuracy validation that should not block Public Beta.

## Consequences

- R3.2-R3.19 implementations must stay within locked scope
- Any scope addition requires a scope-change ADR
- Non-goals are documented in `docs/releases/R3_SCOPE.md`
- R4 deferred items are inventoried for future planning
