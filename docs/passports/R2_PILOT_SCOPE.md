# NordiPass R2 — Pilot Scope

**Stage:** R2.1
**Date:** 2026-07-16
**Status:** COMPLETE — Architecture and Scope Definition
**Dependencies:** R1 Core Catalog

**R1 Prerequisite Assessment:**

| Area | Implementation Status | Formal Evidence | R2.1 Impact |
|---|---|---|---|
| R1 Code | Complete (all 13 stages) | 38 Action classes, 11 tables, 53 API routes, 32 audit events | None — code is stable |
| R1 Tests (CI) | PASS | GitHub Actions CI: all 23 steps pass on MySQL 8 | R2 builds on tested foundation |
| R1 Tests (Local) | FAIL (environment mismatch) | Local Windows PHP/Pest parallel runner incompatibility with MySQL transaction isolation | No impact — CI verifies correctness |
| R1 PHPStan | PASS — 0 errors | `php vendor/bin/phpstan analyse --no-progress` exit 0 | No impact |
| R1 Pint | PASS | `php vendor/bin/pint --test` exit 0 | No impact |
| R1 composer validate | PASS | `composer validate --strict` exit 0 | No impact |
| R1 Formal Release | COMPLETE AND ACCEPTED | R1.13 Final Quality Review: no contradictions found | R2 builds on accepted architecture |
| R1 Blocker | None for R2 | R1 does not define public pages, document management, or DPP | R2 fills these gaps by design |

**R2.1 can proceed.** R1 code is complete and CI-verified. Local test failures are environmental (Windows + Pest parallel runner), not code defects. R2.1 is architecture-only and does not modify R1 code.

---

## 1. Executive Summary

R2 delivers the **Digital Product Passport (DPP) module** for NordiPass, enabling pilot Companies to create, prepare, publish, and maintain immutable product passports with stable public URLs, QR code resolution, and multi-language support.

R2 builds on R1 Core Catalog but introduces a fundamental architectural boundary: **public passport pages never read live Catalog state directly**. Instead, publishing creates an immutable snapshot that becomes the public-facing source of truth. This separation ensures that ongoing Catalog changes (product edits, media replacements, attribute updates) do not silently alter published passports.

The R2 pilot targets a single Swedish manufacturing Company operating under emerging EU Digital Product Passport requirements. The pilot validates the end-to-end workflow from Catalog data preparation through public passport delivery.

**What changes from R1:**
- R1: Internal catalog management only (all pages authenticated)
- R2: Public product passport pages with stable URLs and QR codes
- R1: Live Catalog queries directly from database
- R2: Immutable published snapshots for public delivery
- R1: Single-language content
- R2: Multi-language passport content (Swedish + English minimum)

**Pilot-ready result:** A pilot Company can independently create, prepare, publish, and update a Product Passport without developer intervention, and an anonymous public consumer can access the published passport via a stable URL or QR code.

---

## 2. Pilot Personas

| Persona | R1 Role | R2 Responsibilities |
|---|---|---|
| **Company Owner** | Owner (full permissions) | Approves publication, manages Company branding for public passports, reviews passport analytics |
| **Company Admin** | Admin (full permissions) | Configures passport settings, manages users, publishes/unpublishes/archives passports |
| **Catalog Editor** | Editor (catalog.view, catalog.create, catalog.update, catalog.manage_media) | Prepares product data, fills DPP content sections, adds documents and translations, runs readiness checks, previews passports |
| **Compliance/Publication Manager** | New business role (maps to Admin permissions) | Reviews regulatory compliance, verifies mandatory DPP sections, approves publication, manages document certificates |
| **Public Consumer** | No R1 role (unauthenticated) | Views published passport page, downloads documents, scans QR code, switches languages |
| **NordiPass Support Operator** | Platform SuperAdmin | Monitors passport health, troubleshoots publication failures, manages retention and archival |

**Note:** No new code-level roles are created in R2. The Compliance/Publication Manager is a business persona satisfied by the existing Admin role with `passport.publish` permission. Public Consumer is unauthenticated.

---

## 3. Pilot Workflow

The complete R2 pilot user journey:

```
1. Company creates or imports a Product in R1 Catalog
   → Product exists in draft with default Variant, SKU, GTIN, categories, attributes, media

2. Company navigates to Passport section for the Product
   → System creates initial Passport draft (zero-or-one Passport per Product)

3. Company fills DPP content sections:
   → Product identity (auto-populated from Catalog)
   → Manufacturer information
   → Safety and compliance data
   → Usage instructions
   → Repair and maintenance information
   → Recycling and disposal information
   → Adds DPP-specific documents (certificates, data sheets, declarations)

4. Company adds translations (Swedish + English minimum)
   → Each DPP section can have per-language content
   → Catalog data provides default-language content

5. Company adds documents and certificates
   → Pin specific document versions to the passport
   → Set document language, expiration, public visibility

6. Company runs Passport Readiness check
   → System identifies blockers (missing required data) and warnings (recommended data)
   → Blockers prevent publication; warnings are advisory

7. Company previews the passport
   → Authenticated preview shows exactly what will be published
   → Preview is not publicly accessible, has noindex, requires authorization

8. Company publishes the passport
   → System creates immutable snapshot version
   → Version number assigned (1, 2, 3...)
   → Previous published version becomes superseded
   → Public URL becomes active
   → QR code resolves to the current published version

9. Company downloads QR code
   → QR encodes the stable Passport public URL (not version-specific)

10. Public consumer accesses passport
    → Scans QR or enters URL
    → Views current published version
    → Switches language (Swedish/English)
    → Downloads public documents

11. Company needs to update passport
    → Creates a new draft from the current published version
    → Edits content, replaces documents, updates translations
    → Runs readiness check
    → Publishes new version (version 2)
    → Previous version 1 becomes superseded (preserved in history)
    → Public consumer automatically sees version 2

12. Company needs to unpublish
    → Unpublishes passport (public URL returns 410 Gone)
    → Passport data preserved for future republish
    → Historical versions preserved

13. Company archives passport
    → Product discontinued or passport no longer needed
    → Public URL returns 410 Gone
    → All data preserved in archive
```

---

## 4. MVP Capabilities

### 4.1 Must Have (R2 MVP)

| Capability | Description |
|---|---|
| **Passport Creation** | Create one Passport per Product; auto-populate from Catalog |
| **DPP Content Sections** | Manufacturer info, safety/compliance, usage, repair, recycling |
| **Draft Management** | Single active draft per Passport; editable until published |
| **Publication** | Atomic publish creating immutable snapshot version |
| **Version History** | Store all published versions; view history |
| **Stable Public URL** | Opaque public identifier; stable across versions |
| **QR Code** | Generate QR encoding stable Passport URL |
| **Public Page** | Render current published version for anonymous consumers |
| **Multi-Language** | Swedish (default) + English; per-section translations |
| **Document Attachment** | Upload and pin documents to passport versions |
| **Readiness Check** | Validate passport completeness before publication |
| **Preview** | Authenticated preview of draft before publishing |
| **Unpublish** | Remove public access; preserve data |
| **Republish** | Create new version from current published |
| **Archive** | Remove from active use; preserve history |
| **Audit Trail** | All passport operations logged |
| **Tenant Isolation** | Company-scoped; cross-tenant concealed as 404 |
| **Permission Control** | Role-based access to passport operations |

### 4.2 Should Have (R2 Completion)

| Capability | Description |
|---|---|
| **Document Versioning** | Documents have their own versions; passport pins specific document versions |
| **Media Pinning** | Published passport references immutable asset versions |
| **Historical Version Access** | Public access to previous published versions (if enabled) |
| **Company Branding** | Company logo and public profile on passport page |
| **Expiration Tracking** | Document and certificate expiration monitoring |
| **Basic Analytics** | Public page view counts |

### 4.3 Could Have (R3 Candidates)

| Capability | Description |
|---|---|
| **Variant-Specific Passports** | Separate passports per Variant |
| **Bulk Publication** | Publish multiple passports simultaneously |
| **Scheduled Publication** | Future-dated automatic publication |
| **Approval Workflow** | Multi-step review before publication |
| **Custom DPP Templates** | Configurable section layouts |
| **API for Passport Data** | Public API to query passport data |
| **Advanced Analytics** | Detailed view tracking, geography, device |
| **Email Notifications** | Document expiration alerts |

---

## 5. Pilot Acceptance Criteria

The R2 pilot is accepted when:

1. A real pilot Company (Swedish manufacturer) can, without developer assistance:
   - Create a Product in R1 Catalog with all required data
   - Create and fill a Product Passport with DPP content
   - Add documents and translations
   - Run readiness check and see actionable results
   - Preview the passport before publication
   - Publish the passport and receive a stable public URL
   - Download and test a QR code
   - Access the passport as an anonymous public consumer
   - Update the passport by creating and publishing a new version
   - Unpublish and republish the passport
   - Archive the passport

2. The public passport page:
   - Loads in under 2 seconds (uncached)
   - Loads in under 200ms (cached)
   - Displays correctly in Swedish and English
   - Provides document downloads
   - Is not indexable by search engines during pilot (robots noindex)
   - Does not expose internal IDs, storage paths, or tenant metadata
   - Returns appropriate error codes (404/410) for invalid or unpublished passports

3. Zero data leaks between Companies (tenant isolation verified).

4. All publication operations are atomic (no partial publications).

5. Changing Catalog data does not modify already-published passports.

---

## 6. Non-Goals (Explicitly Excluded from R2)

| Non-Goal | Reason | Target |
|---|---|---|
| Variant-specific passports | MVP uses Product-level passport with Variant data included | R3 |
| Public product listing/storefront | Different from DPP; requires separate design | R3 |
| Custom domains for passports | Infrastructure complexity beyond pilot scope | R3 |
| DPP API for external systems | Public API for programmatic passport access | R3 |
| Bulk import of passport data | Excel/CSV import for DPP content | R3 |
| AI-generated content | AI-assisted DPP section filling | Future |
| Approval workflow | Multi-step review/publish approval chain | R3 |
| Scheduled publication | Future-dated automatic publish | R3 |
| Passport comparison | Side-by-side version comparison | R3 |
| PDF generation of passport | Server-side PDF rendering | R3 |
| Integration with EU DPP registry | No registry exists yet; prepare data model only | Future |
| Real-time collaboration | Multiple users editing same draft | Future |
| Passport templates | Reusable section templates across products | R3 |
| Blockchain/DLT anchoring | Not required for pilot | Future |

---

## 7. Dependencies

### 7.1 R1 Core Catalog Dependencies

| R1 Component | R2 Usage |
|---|---|
| Product aggregate | Primary data source for passport identity |
| ProductVariant | Default Variant + all public Variants included in passport |
| Category tree | Product classification in passport |
| AttributeDefinitions & Values | DPP section content; technical specifications |
| ProductMedia | Passport images pinned at publication |
| Product lifecycle (draft/active/archived) | Catalog readiness prerequisite for passport publication |
| Company model | Passport ownership; tenant isolation |
| CurrentCompany (SessionCurrentCompany, TokenCurrentCompany) | Tenant context for all passport operations |
| CompanyPermission & CompanyPermissionMatrix | Extended with passport permissions |
| CompanyAuthorizer | Authorization for passport operations |
| AuditLog & AuditLogger | Extended with passport audit events |
| SensitiveDataSanitizer | Sanitize passport audit properties |
| HasUuid trait | UUID generation for passport entities |
| Tenant middleware chain | auth → verified → company.resolve → company.selected → member → active |
| API middleware chain | Token → Ability → Company → Membership → Policy |
| Media storage (catalog_media disk) | Pinned asset storage for published passports |
| SHA-256 checksums | Asset integrity verification |

**Note:** R1 Catalog does not provide: public document management, multi-language translations, QR code generation, DPP-specific content sections, or public-facing pages. These are all R2 additions and do not represent R1 gaps.

### 7.2 Infrastructure Dependencies

| Infrastructure | R2 Usage |
|---|---|
| MySQL 8.0 | Database for passport tables |
| Laravel cache | Public page caching (Redis recommended) |
| Queue system | After-commit side effects (QR generation, analytics) |
| Scheduler | Document expiration monitoring, stale draft cleanup |
| Private filesystem | Document and pinned asset storage |
| Public web server | Public passport page delivery |

---

## 8. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| EU DPP requirements change during pilot | MEDIUM | Design data model to accommodate unknown future fields via JSON metadata |
| Industry-specific DPP requirements differ from general model | MEDIUM | Configurable section definitions; pilot Company validates template |
| Document retention legal requirements unclear | LOW | Default retention: keep all published versions indefinitely; add configurable retention in R3 |
| Translation completeness blocks publication | MEDIUM | Required-language gate in readiness; partial translations allowed with warnings |
| Snapshot storage growth over time | LOW | Estimate: ~100KB per snapshot × 1000 products × 10 versions = ~1GB; acceptable for pilot |
| Public cache invalidation after publish | MEDIUM | Cache key includes version ID; atomic pointer swap + cache clear in same transaction |
| Privacy of analytics data | LOW | Pilot analytics are anonymous page view counters only; no tracking cookies |
| Asset immutability failure (media replaced in Catalog) | HIGH | **Mandatory: publish must pin asset checksum and version; public delivery validates against pinned reference** |
| Pilot onboarding complexity | MEDIUM | Default DPP template with pre-filled sections; guided workflow |
| Concurrent publication requests | LOW | Row-level locking on Passport; idempotent publication check |

---

## 9. Success Metrics

| Metric | Target | Measurement |
|---|---|---|
| Time to first published Passport | < 30 minutes | From Product creation to published passport (with pre-filled Catalog data) |
| Percentage of Products passing readiness | > 80% | Products that pass readiness on first check |
| Publication failure rate | < 2% | Failed publications / total publication attempts |
| Public page availability | > 99.9% | Successful responses / total requests (excluding 404/410) |
| QR resolution success | > 99.5% | Successful page loads from QR scans |
| Document download success | > 99% | Successful document downloads from public page |
| Number of support interventions per pilot Company | < 5 | Support tickets related to passport workflow during 4-week pilot |
| Time to publish new version | < 5 minutes | From "Create New Draft" to published (for minor updates) |
| Cache hit rate for public page | > 90% | Cached responses / total public requests |
| Cross-tenant isolation violations | 0 | Any instance of Company A seeing Company B passport data |

---

## 10. Out of Scope (R2 Deferred to R3+)

The following items are explicitly deferred beyond R2. References to these capabilities in domain documents are marked as deferred.

### R3 Candidates
- Variant-specific passports
- Approval workflow (multi-step publish review)
- Scheduled publication
- Public passport API
- Advanced analytics dashboard
- Bulk operations (publish, unpublish, archive)
- Passport duplication/clone
- Custom DPP section templates
- PDF generation

### Future Candidates (No Target Stage)
- EU DPP registry integration
- Blockchain/DLT anchoring
- AI content generation
- Real-time collaboration
- Public storefront / product listing pages
- Custom domain support

---

## 11. References

- **R1 Domain:** [../catalog/CATALOG_DOMAIN.md](../catalog/CATALOG_DOMAIN.md)
- **R1 Decisions:** [../catalog/CATALOG_DECISIONS.md](../catalog/CATALOG_DECISIONS.md)
- **R1 Scope:** [../catalog/R1_CATALOG_SCOPE.md](../catalog/R1_CATALOG_SCOPE.md)
- **R1 Final Review:** [../catalog/R1_FINAL_QUALITY_REVIEW.md](../catalog/R1_FINAL_QUALITY_REVIEW.md)
- **R2 Publication Domain:** [R2_PUBLICATION_DOMAIN.md](R2_PUBLICATION_DOMAIN.md)
- **R2 Publication Decisions:** [R2_PUBLICATION_DECISIONS.md](R2_PUBLICATION_DECISIONS.md)
