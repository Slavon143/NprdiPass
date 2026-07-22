# R3 — Public Beta Scope

## Status: R3.1 Architecture Lock — APPROVED

---

## 1. R3 Mandatory Capabilities

| Stage | Name | Scope Summary |
|-------|------|---------------|
| R3.2 | Readiness Profiles v2 and UX | Versioned profiles, immutable profile versions, versioned rule weights, score algorithm v2, historical reproducibility, category/schema profile selection, human-readable rules, source/provenance, fix links, legal disclaimer, accessible UX |
| R3.3 | Advanced DPP Sections | Materials, material percentages, recycled content, environmental metrics, environmental claims, usage and care, repair, spare parts, recycling, take-back program, warranty, support, responsible operator, compliance metadata |
| R3.4 | Documents and Compliance Workflow | Document types, certificate metadata, declaration of conformity, document versions, expiration, review, approval, public/private visibility, product and variant associations, passport associations, notifications |
| R3.5 | Taxonomy and Attribute Governance | Category templates, required attributes, controlled vocabularies, attribute deprecation, safe option lifecycle, schema assignment, readiness profile assignment, dependency diagnostics, limited safe migration tools |
| R3.6 | Import/Export Production Upgrade | Excel, CSV, mapping templates, preview, validation, error reports, chunking, idempotency, resume, bulk update, export, large-file tests |
| R3.7 | Fortnox Integration | OAuth, connections, article import, external identity mapping, source-of-truth policy, scheduled synchronization, polling/webhooks, conflict handling, reconciliation, retries, audit, sync history |
| R3.8 | Billing, Plans and Entitlements | Stripe, plans, prices, trials, subscriptions, checkout, customer portal, invoices, upgrade, downgrade, failed payment, grace period, cancellation, webhook inbox, reconciliation, server-side entitlements, usage limits |
| R3.9 | QR and Product Analytics | QR scans, public Passport views, time-series analytics, product analytics, privacy-safe country/device data, readiness trends, publication metrics, exports, retention |
| R3.10 | Team Roles and Approval Workflow | Granular permissions, compliance manager, publisher, auditor, maker-checker, approval requests, approval decisions, publication policies, approval audit |
| R3.11 | Public Passport v2 | Mobile-first UX, multilingual content, variant selection, document downloads, accessibility, version display, withdrawn/archived states, performance, SEO policy, privacy |
| R3.12 | QR and Label Management | Stable resolver, QR styles, quiet zone, contrast, logo limits, SVG, PNG, PDF, batch generation, label templates, ZIP export, decode verification, print guidance |
| R3.13 | Public API and Webhooks | Passport API, publication API, documents API, analytics API, import API, webhook endpoints, delivery logs, signatures, replay protection, retries, rate limits, idempotency, OpenAPI |
| R3.14 | Notifications and Job Center | In-app notifications, email preferences, document expiry, import completion/failure, Fortnox sync failure, publication failure, readiness changes, billing events, job status, safe retry/cancel |
| R3.15 | Platform Operations | Organization support view, restricted support access, feature flags, job monitoring, failed-job recovery, tenant health, incident tools, operational metrics, abuse controls |
| R3.16 | Security and Privacy Hardening | GDPR workflows, data export/deletion, retention, DPA/processors, public analytics privacy, CSP, upload hardening, dependency scanning, permission review, cross-tenant testing, security runbooks |
| R3.17 | Performance and Observability | Structured logs, metrics, traces, SLO, queue monitoring, query profiling, caching, large-catalog tests, public resolver performance, storage monitoring |
| R3.18 | Public Beta Onboarding | Guided onboarding, sample data, first product, first Passport, readiness explanation, first publication, QR verification, billing activation, help content, support path |
| R3.19 | R3 Final Acceptance Verification | Fresh install, upgrade from R2, full MySQL regression, billing E2E, Fortnox E2E, import E2E, publication E2E, QR/public E2E, cross-tenant security, backup/restore, performance, accessibility, Public Beta release verdict |

---

## 2. R3 Non-Goals (Explicitly Excluded)

| # | Capability | Deferred To |
|---|-----------|-------------|
| 1 | Production AI/RAG assistant | R4+ |
| 2 | Automated legal certification | R5+ |
| 3 | Official EU compliance score | R4+ |
| 4 | Support for every DPP industry | Post-Public Beta |
| 5 | Enterprise SSO/SAML/SCIM | R4+ |
| 6 | Multi-region active-active deployment | R5+ |
| 7 | Arbitrary workflow builder | R4+ |
| 8 | Unlimited white-label customization | R4+ |
| 9 | Integration marketplace | R5+ |
| 10 | Universal taxonomy migration engine | R4+ |
| 11 | Predictive AI analytics | R5+ |
| 12 | Native mobile applications | Post-Public Beta |
| 13 | Custom payment engine outside Stripe | R4+ |
| 14 | Crypto payments | Not planned |
| 15 | Multi-language machine translation | R4+ |
| 16 | B2B/reseller portal | R4+ |
| 17 | Custom domain/white-label public passport | R4+ |
| 18 | Blockchain-anchored DPP | Not planned |

---

## 3. R3 Deferred Items (R4)

| # | Capability |
|---|-----------|
| 1 | Bulk operations across companies |
| 2 | Custom report builder |
| 3 | Advanced taxonomy migration engine |
| 4 | Third-party ERP integrations (beyond Fortnox) |
| 5 | White-label configuration UI |
| 6 | Multi-language admin interface |
| 7 | Dark mode |
| 8 | Custom public passport domains |

---

## 4. Removed from Roadmap

None. All originally planned capabilities are either in R3, deferred to R4+, or explicitly excluded as non-goals.

---

## 5. Scope Lock Validation

| Criteria | Status |
|----------|--------|
| R3 scope is complete | YES |
| No ambiguous "to be decided later" items | YES |
| All R3.2-R3.19 stages have clear goals | YES |
| Non-goals documented | YES |
| Deferred items assigned to release | YES |
| No cyclic hard dependencies | YES |
| Architecture baseline from R2 accepted code | YES |

---

## 6. Scope Change Process

Any addition to R3 scope requires:
1. A scope-change ADR (ADR-R3-0XX)
2. Impact analysis on dependency graph
3. Risk assessment
4. Explicit approval before implementation begins
