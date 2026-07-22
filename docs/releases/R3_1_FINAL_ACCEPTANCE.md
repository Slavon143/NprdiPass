# NordiPass R3.1 — Final Acceptance Report

## 1. Final verdict

```text
R3_1_ACCEPTED
```

R2 remains formally closed as `R2_ACCEPTED`. R3 architecture baseline is approved, R3 scope is locked, and R3.2 may begin.

## 2. Executive summary

R3.1 inspected the accepted R2 repository baseline at commit `c9f0794cabe30427f6643831c96bda7ac7411599` on branch `master`, using the R2 acceptance evidence in `docs/release/R2_FINAL_ACCEPTANCE.md`, `docs/release/R2_TEST_EVIDENCE.md`, and `docs/release/R2_TRACEABILITY_MATRIX.md`.

The R3 architecture package now defines:

- R2 implementation/database/routes/permission/events/feature-flag baseline.
- R3 mandatory scope and non-goals.
- Acyclic R3 dependency map and implementation order.
- R2 → R3 migration and backward-compatibility strategy.
- API, event, public URL, QR, readiness, DPP, Fortnox, billing, analytics, approvals, notifications, operations, security, privacy, observability, test, and release-gate contracts.
- Implementation-ready contracts for R3.2-R3.19.

One dependency security blocker was found during validation: `guzzlehttp/guzzle` 7.14.1 had four current Composer audit advisories. R3.1 remediated it with a targeted lockfile update to `guzzlehttp/guzzle` 7.15.1 and `guzzlehttp/psr7` 2.13.0. Full regression passed after the update.

## 3. R2 accepted baseline

| Area | Actual implementation | R3 impact |
|------|-----------------------|-----------|
| Authentication | Laravel Breeze auth, profile routes, password/email verification | Keep existing auth; R3 roles/entitlements layer on top |
| Companies and tenancy | `Company`, `CompanyMembership`, session/token current company resolvers | All R3 modules must be company-scoped |
| Memberships/permissions | Company roles and permission seeder, policies, Spatie Permission | R3.10 extends granular roles without encoding billing entitlements as permissions |
| Audit | `activity_log` plus audit context and listeners | R3 events/jobs/integrations must emit tenant-aware audit |
| Catalog | Categories, products, variants, attributes, options, media, lifecycle actions | R3.5/R3.6/R3.7 consume existing catalog Actions and Policies |
| Documents | Product documents and immutable versions | R3.4 adds compliance workflow additively |
| Passports | Product passport drafts, versions, assets, publication idempotency | R3.3/R3.11 must preserve immutable published snapshots |
| Readiness | `nordipass-pilot` v1, 66-rule evaluator, validation evidence tables | R3.2 adds versioned profiles with historical reproducibility |
| QR/public resolver | Stable `/p/{publicId}` resolver and QR image routes | R3.12 must not break existing QR targets |
| API | `/api/v1` catalog/company/passport endpoints with Sanctum token abilities | R3.13 remains additive under `/api/v1` |
| Queues/scheduler/ops | Jobs tables, failed jobs, prune/backup/integrity schedule | R3.14/R3.15/R3.17 extend job center and observability |

## 4. R3 scope

The locked scope is in `docs/releases/R3_SCOPE.md`. Mandatory stages are R3.2 through R3.19: Readiness Profiles v2, Advanced DPP, Documents/Compliance, Taxonomy Governance, Import/Export, Fortnox, Billing, Analytics, Roles/Approval, Public Passport v2, QR/Labels, API/Webhooks, Notifications/Job Center, Platform Operations, Security/Privacy, Performance/Observability, Public Beta Onboarding, and Final Acceptance.

## 5. R3 non-goals

R3 excludes production AI/RAG assistant, automated legal certification, official EU compliance score, every-DPP-industry support, enterprise SSO/SAML/SCIM, multi-region active-active, arbitrary workflow builder, unlimited white-label customization, integration marketplace, universal taxonomy migration engine, predictive AI analytics, native mobile applications, custom non-Stripe payment engine, and crypto payments. Additions require a scope-change ADR.

## 6. Dependency graph

The dependency graph is fixed in `docs/releases/R3_DEPENDENCY_MAP.md`. Hard/data-contract dependencies are acyclic; event-source modules emit events before R3.14 consumes them, and R3.19 depends on all mandatory stages.

## 7. Architecture decisions

| ADR | Decision |
|-----|----------|
| ADR-R3-001 | Public Beta scope and non-goals locked |
| ADR-R3-002 | Versioned immutable Readiness Profiles v2 |
| ADR-R3-003 | DPP section ownership/inheritance and published serialization |
| ADR-R3-004 | Documents and compliance workflow |
| ADR-R3-005 | Taxonomy and attribute governance without universal migration engine |
| ADR-R3-006 | Fortnox source-of-truth/conflict policy |
| ADR-R3-007 | Stripe billing, projections, entitlements |
| ADR-R3-008 | Analytics privacy and retention |
| ADR-R3-009 | API and webhook versioning |
| ADR-R3-010 | Feature flags and rollout |
| ADR-R3-011 | R2 → R3 migration strategy |
| ADR-R3-012 | Public Passport and QR compatibility |

## 8. Database migration strategy

R3 migrations are MySQL 8 only, forward-safe, non-destructive by default, and use expand → backfill → dual-read/write where needed → switch → contract in a later release. Published passport versions, assets, validation evidence, audit records, QR public IDs, documents, media, catalog data, users, companies, memberships, and API tokens must survive upgrade from R2.

## 9. Backward compatibility

R3 must not break R2 QR codes, `/p/{publicId}` resolver URLs, published Passport snapshots, readiness history, audit records, existing document links, existing route names without migration plan, or `/api/v1` clients without deprecation.

## 10. Readiness Profiles v2

R3.2 stores stable profile code, immutable profile versions, rule weights, algorithm version, human-readable rule metadata, source/provenance, fix links, and v1 migration/fallback from `nordipass-pilot`. Historical validation evidence remains reproducible.

## 11. DPP architecture

DPP section ownership is product-first with controlled variant overrides. Draft data remains mutable; published serialization freezes section payloads into immutable Passport versions.

## 12. Fortnox architecture

Fortnox is an integration source, not a replacement catalog service. Local catalog/domain Actions remain the write path. Source-of-truth, external identity mapping, reconciliation, retries, audit, and tenant-scoped token storage are defined in ADR-R3-006.

## 13. Billing architecture

Stripe is the billing source of truth. NordiPass stores local projections, entitlements, usage counters, webhook inbox records, reconciliation state, and failure/grace-period handling. Entitlements are server-side and separate from permissions and feature flags.

## 14. Security and privacy

R3 security gates cover authorization, tenant isolation, CSRF, OAuth state, webhook signatures, replay prevention, rate limits, upload hardening, SSRF, secret storage, audit, privacy, retention, and dependency scanning. Analytics stores privacy-safe aggregates and avoids raw IP/full user-agent retention by default.

## 15. Feature flags

R3 flags include readiness profiles v2, advanced DPP sections, compliance workflow, taxonomy governance, production imports, Fortnox, billing, analytics, approval workflow, public Passport v2, QR labels, public API extensions, notifications center, and platform operations. Security controls are not ordinary feature flags.

## 16. Test strategy

The complete R3 strategy is in `docs/architecture/R3_TEST_STRATEGY.md`: unit/domain, MySQL integration, feature/API, jobs/events, browser/E2E, security, performance, and accessibility tests. SQLite is forbidden.

## 17. Release gates

The measurable gate matrix is in `docs/releases/R3_RELEASE_GATES.md`: architecture, data, security, commercial, integration, public journey, operations, and final acceptance gates.

## 18. Documentation changes

| File | Change |
|------|--------|
| `docs/architecture/R3_ARCHITECTURE_BASELINE.md` | R2 architecture/database/routes/permissions/events/features/limitations baseline |
| `docs/architecture/R3_DATA_MIGRATION_STRATEGY.md` | R2 → R3 migration and compatibility policy |
| `docs/architecture/R3_SECURITY_AND_PRIVACY.md` | Security/privacy gates |
| `docs/architecture/R3_TEST_STRATEGY.md` | R3 test strategy |
| `docs/architecture/adr/R3-001...R3-012` | R3 ADR package |
| `docs/releases/R3_SCOPE.md` | Scope/non-goals |
| `docs/releases/R3_DELIVERY_PLAN.md` | Implementation order |
| `docs/releases/R3_DEPENDENCY_MAP.md` | Acyclic dependency graph |
| `docs/releases/R3_RELEASE_GATES.md` | Release gates |
| `docs/releases/R3_IMPLEMENTATION_CONTRACTS.md` | Stage contracts R3.2-R3.19 |
| `docs/releases/R3_1_FINAL_ACCEPTANCE.md` | This final report |

## 19. Production-code changes

No PHP/Blade/JavaScript/migration/config production code changes were required.

Dependency lockfile remediation was required:

| File | Reason | Verification |
|------|--------|--------------|
| `composer.lock` | Remediate Composer audit advisories in `guzzlehttp/guzzle` | Composer audit now reports no security vulnerability advisories; full test suite passes |

## 20. Validation evidence

| Command | Exit code | Result |
|---------|-----------|--------|
| `composer validate` | 0 | Passed with PHP 8.5 deprecation notices |
| `php artisan config:clear` | 0 | Passed with temporary `pdo_mysql` extension enabled |
| `php artisan cache:clear` | 0 | Passed with temporary `pdo_mysql` extension enabled |
| `php artisan route:clear` | 0 | Passed |
| `php artisan view:clear` | 0 | Passed |
| `php artisan migrate:fresh --env=testing` | 0 | 36 migrations ran on MySQL testing database |
| `php artisan migrate:status --env=testing` | 0 | All 36 migrations `Ran` with `pdo_mysql`/`mbstring` enabled |
| `vendor/pestphp/pest/bin/pest` | 0 | 2,073 tests; 2,072 passed; 1 skipped; 7,794 assertions; 283.558 s after dependency remediation |
| `vendor/bin/pint --test` | 0 | Passed |
| `vendor/bin/phpstan analyse` | 0 | Passed, 0 errors |
| `npm ci` | 0 | Passed after approved unsandboxed rerun; 204 packages installed; 0 vulnerabilities |
| `npm run build` | 0 | Vite build passed; CSS 58.03 kB, JS 45.26 kB |
| `composer audit` | 0 | Initial run found 4 Guzzle advisories; after lockfile remediation, no advisories found |
| `npm audit` | 0 | Passed after approved unsandboxed rerun; 0 vulnerabilities |
| `php artisan route:list` | 0 | 220 routes |
| `php artisan config:cache` | 0 | Passed |
| `php artisan route:cache` | 0 | Passed |
| `php artisan view:cache` | 0 | Passed |
| `git diff --check` | 0 | Passed |
| `git status --short` | 0 | Expected docs plus `composer.lock` changes only |

## 21. Contradictions

| Contradiction | Resolution | Document |
|---------------|------------|----------|
| Draft dependency map had reciprocal data/event edges | Reoriented provider → consumer edges and kept hard graph acyclic | `docs/releases/R3_DEPENDENCY_MAP.md` |
| Current Composer audit contradicted R2 no-advisory evidence | Treated current advisory data as release-blocking and updated Guzzle | `composer.lock`, this report |
| Local PHP CLI had required extensions commented out | Used non-invasive temporary extension flags for validation; no global PHP config edit | This report |
| Global npm shim/cache initially failed | Used working Program Files npm and approved unsandboxed install/audit | This report |

## 22. Residual risks

| Risk | Owner | Closing stage | Blocking? |
|------|-------|---------------|-----------|
| PHP 8.5 emits Composer dependency deprecation notices | Platform operations | R3.16/R3.17 tooling hardening | No |
| R3.2 must choose concrete profile table names and migrations from the approved ADR | R3.2 owner | R3.2 | No |
| Fortnox and Stripe provider sandbox credentials are not part of R3.1 | R3.7/R3.8 owners | R3.7/R3.8 | No |

No residual risk affects data loss, tenant isolation, backward compatibility, or the ability to begin R3.2.

## 23. Git diff summary

Expected changes:

- R3 documentation package under `docs/architecture`, `docs/architecture/adr`, and `docs/releases`.
- `composer.lock` dependency security remediation only.

No random files, debug code, secrets, or temporary npm cache artifacts remain. `git diff --check` passes.

## 24. Next stage

```text
R3.1 formally closed.
Begin R3.2 — Readiness Profiles v2 and UX.
```
