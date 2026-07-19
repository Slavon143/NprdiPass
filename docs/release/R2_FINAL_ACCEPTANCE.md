# NordiPass R2 Final Acceptance

Acceptance date: 2026-07-19  
Environment: Windows, PHP 8.5.2, Laravel 12, MySQL 8 (`nordipass_testing`), Node.js/npm, Chromium/Puppeteer  
Evidence is based on the final clean MySQL run and browser run `DEMO-20260719-104351`.

## 1. Final verdict

```text
R2_ACCEPTED
```

## 2. Executive summary

R2 catalog, DPP authoring, readiness, publication, immutable snapshots, public assets, QR, deletion/retention, authorization, tenant isolation, operations, and browser workflows were inspected against code, migrations, tests, and running behavior. The review fixed atomic bulk-operation defects, restore-integrity deletion defects, legacy double-encoded language JSON, product-return handling, a publication race, missing draft preview, readiness evidence/UI gaps, and false-positive document coverage in the browser scenario.

The final clean MySQL suite passed 2,073 tests with 7,794 assertions; the strengthened browser scenario passed 45/45 steps. R2 is ready for formal closure and R3 may begin.

## 3. Baseline state

| Command / area | Result before fixes |
|---|---|
| `composer validate --strict` | Passed; Composer 2.6 emitted PHP 8.5 deprecation notices. |
| `optimize:clear`, `migrate:fresh --env=testing` | Passed on MySQL 8. |
| Unit suite | 3 failures: registry assertions expected 65 rules while runtime contained 66. |
| Catalog-focused suite | 7 failures: double-encoded `enabled_languages` and product back-navigation behavior. |
| Passport Feature suite | 484 tests / 1,350 assertions passed. |
| `tests/Integration` | Directory does not exist; integration coverage is located under Feature tests. |
| Pint / PHPStan / Vite | Passed at baseline. |
| Deploy preflight | 15/15 passed. |
| Existing browser runner | First historical run could report uploaded documents as passed even when validation rejected them; public document links were optional. |

## 4. R2 scope inventory

| Capability | Route/UI | Backend | Test | Status |
|---|---|---|---|---|
| Catalog products and lifecycle | `/catalog/products/*` | Catalog actions/controllers | Catalog Feature suites | Passed |
| Categories and hierarchy | `/settings/catalog/categories/*` | Category actions | Category delete/bulk tests | Passed |
| Attributes and values | `/catalog/attributes/*` | Attribute actions | Attribute delete/bulk tests | Passed |
| Variants, media, documents | Product sub-resources | Scoped models/actions/storage | Catalog and passport asset tests | Passed |
| DPP authoring | `/catalog/products/{uuid}/passport/edit` | Draft actions and validator | Authoring/Web suites | Passed |
| Readiness | Web and API readiness routes | 66-rule evaluator and immutable evidence | Unit, API, Web, evidence tests | Passed |
| Draft preview | `/catalog/products/{uuid}/passport/preview` | Snapshot builder + public resolver preview mode | `DppWebTest` | Passed |
| Publication/versioning | publish/unpublish/archive/restore routes | Transactional publication action | Publication/concurrency/immutability suites | Passed |
| Public passport/assets | `/p/{publicId}` and asset routes | Immutable published snapshot resolver | Public passport suites + browser | Passed |
| Stable QR | Passport QR routes | QR service keyed by stable public ID | QR suites + browser | Passed |
| Audit/security | `/audit`, policies, permission gates | Company authorization + activity log | Authorization/cross-tenant/CSRF tests | Passed |
| Operations | Artisan deploy/backup/schedule/queue commands | Infrastructure services | Feature tests + CLI verification | Passed |

## 5. Data relationship map

| Entity | Relationship and invariant |
|---|---|
| Category | Company-scoped hierarchy; products use a pivot; deletion is blocked while any active or archived product depends on it. |
| Product | Company aggregate root for variants, media, documents, attributes, and one passport. |
| Variant | Belongs to product; one variant can be the default; lifecycle status is captured in published snapshots. |
| Attributes | Company definitions/options feed product and variant values; referenced definitions cannot be deleted. |
| Media | Company/product scoped; immutable public assets are copied/pinned at publication. |
| Documents | Product document owns immutable versions; a DPP reference is resolved and pinned to a document-version UUID. |
| Passport | One per product; stable `public_id`; state points to current draft and current published version. |
| Revision | Draft passport version with optimistic `draft_revision`; published versions are immutable. |
| Validation | Each evaluation creates immutable run/result evidence with profile, versions, weights, score, context identity, and timestamp. |
| Published version | Immutable normalized payload, catalog snapshot, media, documents, readiness evidence, and version number. |
| QR | Resolves the stable passport public URL; republishing does not change the QR target. |

## 6. Readiness verification

| Item | Verified value |
|---|---|
| Config file | `config/passport_readiness.php` |
| Calculator file | `app/Services/Passports/Readiness/ReadinessScoreCalculator.php` |
| Blade file | `resources/views/passports/readiness.blade.php` |
| Profile | `nordipass-pilot` |
| Profile version | `1` |
| Rule-set / algorithm version | `1` / `1` |
| Rules | 66 total: 33 blockers, 32 warnings, 1 recommendation |
| Weights | blocker 10, warning 3, recommendation 1 |
| Formula | `round(earned applicable points / total applicable points * 100)` clamped to 0..100 |
| Rounding | PHP integer `round`; zero applicable points yields 100. |
| Not applicable | Excluded from numerator and denominator; retained in counts/evidence. |
| Historical snapshot | Published version stores the readiness evidence used for that publication; later draft/catalog changes do not rewrite it. |
| Publication blocking | Any failed blocker prevents publication regardless of numeric score; publication re-evaluates under a row lock. |

The UI now opens “Needs attention”, collapses “Passed” and “Not applicable”, explains earned/lost points, shows source/current/result/requirement/fix, and hides technical rule codes outside debug mode.

## 7. Traffic signals diagnosis

| Field | Result |
|---|---|
| Entity identity | Deterministic equivalent fixture created because the named record was absent after clean seeding; product UUID `38fe54ff-3c76-4e76-a70b-21c309a91d3b`. |
| Revision | Draft revision 1 |
| Profile / schema | `nordipass-pilot` v1 / schema `1`; rule set v1, algorithm v1 |
| Rule counts | 34 passed, 8 blockers failed, 21 warnings failed, 1 recommendation failed, 2 not applicable (66 total) |
| Points / score | 277 / 421 applicable points; 66% |
| Issues | Minimal catalog/passport fixture lacked publication-critical identity/content/assets and optional enrichment; readiness correctly identified all deficits. |
| Fixes | No business-data inflation was applied. The acceptance fix was reproducible diagnosis and correct 66-rule/weighted-score evidence. |
| Final result | Correctly **Not Ready**; fixture removed during acceptance cleanup. |

The earlier supplied 59% / 65-rule-derived expectation was stale and is not reproducible with the current 66-rule registry.

## 8. Reflective Safety Vest diagnosis

| Field | Result |
|---|---|
| Entity identity | Seeded showcase product UUID `549cd746-50aa-42f6-a4cf-f04d1ed16632`, category Protective Clothing, default variant Yellow / L. |
| Revision | Draft revision 1 |
| Profile / schema | `nordipass-pilot` v1 / schema `1`; rule set v1, algorithm v1 |
| Rule counts | 42 passed, 3 blockers failed, 18 warnings failed, 1 recommendation failed, 2 not applicable (66 total) |
| Points / score | 336 / 421 applicable points; 80% |
| Issues | Showcase content remains intentionally incomplete for publication and exposes actionable blocker/warning groups rather than masking them. |
| Fixes | Readiness presentation/evidence were corrected; the showcase business record was not falsified to raise its score. |
| Final result | Correctly **Not Ready**; deterministic demo data removed after browser evidence. |

The earlier supplied 92% / 65-rule-derived expectation was stale and is not used as acceptance truth.

## 9. Deletion and retention matrix

| Entity | Dependency | Old behavior | Expected behavior | Implemented behavior | Test |
|---|---|---|---|---|---|
| Category | Active or archived products | Archived dependencies could be missed. | Preserve restore integrity. | Delete blocked for any dependent product. | `CategoryDeleteTest` |
| Attribute definition | Product/variant values, including archived | Values could be hard-deleted or archived dependencies missed. | Preserve referenced values and restores. | Delete blocked while any value exists. | `AttributeDeleteTest` |
| Bulk category/attribute | Mixed own + foreign/unknown UUIDs | Owned rows could mutate before failure. | All-or-nothing tenant-safe operation. | Cardinality validated before mutation. | Bulk/delete tests |
| Bulk product status/archive | Mixed own + foreign/unknown UUIDs | Partial mutation possible. | Atomic rejection. | Full scoped UUID-set validation before mutation. | `ProductBulkArchiveTest` and status tests |
| Published passport | Public URL, QR, snapshots/assets | Destructive coupling risk. | Retain immutable history and stable identity. | Lifecycle archive/unpublish; published versions/assets remain immutable. | Publication/public suites |
| Documents/media | Published snapshot references | Mutable source could leak into history. | Pin/copy immutable publication assets. | Resolver reads published assets only. | Asset/document pinning tests |

Detailed policy is in `docs/release/R2_DELETION_AND_RETENTION.md`.

## 10. Fixed defects

| Defect | Root cause | Risk | Fix | Verification |
|---|---|---|---|---|
| Partial bulk mutations | Scoped query result was mutated without proving all requested UUIDs were owned/resolvable. | Cross-tenant probing and inconsistent state. | Compare requested/fetched cardinality before mutation. | Catalog focused tests + full suite |
| Category/attribute restore breakage | Dependency checks ignored archived consumers or removed values. | Restored products could reference missing structure. | Block deletion for all dependencies; retain values. | Delete tests |
| Double-encoded languages | Legacy strings were encoded as JSON strings. | Invalid DPP state and inconsistent reads. | Model normalization + forward data repair + MySQL CHECK. | Schema/model/constraint tests |
| Product return URL | Redirect parameter was always appended. | Broken direct-navigation behavior. | Append only when supplied and validated. | `ProductBackNavigationTest` |
| Publication race | Passport was not re-fetched/locked inside the transaction. | Publish stale draft/evidence. | Row lock and fresh evaluation in transaction. | Concurrency/publication suites |
| Published republish status rule | Published passport with a mutable draft was rejected. | Legitimate new version could not publish. | Treat published + current draft as editable. | Readiness/publication suites |
| Missing draft preview | Only published public rendering existed. | Editors could not verify draft rendering safely. | Authenticated tenant-scoped no-store/noindex preview using shared renderer. | `DppWebTest` + manual desktop/mobile |
| Readiness ambiguity | Score and failed rules were not sufficiently explained. | Misinterpretation of readiness. | Weighted breakdown, grouped UX, provenance, immutable run/results. | Unit/API/Web/evidence tests |
| Browser false positives for documents | Invalid fixture enums plus weak assertions. | E2E passed without creating or publishing documents. | Valid enum metadata, UUID assertion, draft sync, mandatory public link assertion. | 45/45 browser run |

## 11. Changed files

| File / group | Change |
|---|---|
| `app/Actions/Catalog/**` | Atomic bulk validation and dependency-safe deletion. |
| `app/Actions/Passports/PublishProductPassport.php` | Transactional lock and fresh publication evaluation. |
| `app/Actions/Passports/RecordPassportValidationRun.php` | Immutable readiness evidence recording. |
| `app/Models/Passports/**Validation*` | Validation run/result persistence. |
| `app/Models/Passports/ProductPassport.php` | Legacy language normalization and array persistence. |
| `app/Services/Passports/Readiness/**` | Weighted breakdown, rule correctness, presentation metadata. |
| `app/Services/Passports/Public/PublicPassportResolver.php` | Shared immutable public and safe preview rendering. |
| `app/Http/Controllers/Catalog/ProductPassportController.php`, `routes/web.php` | Tenant-scoped draft preview endpoint. |
| `resources/views/passports/**` | Explainable readiness and preview/public rendering. |
| `database/migrations/2026_07_18_000002_*` | Immutable readiness evidence schema/triggers. |
| `database/migrations/2026_07_19_000001_*` | Language repair and MySQL JSON CHECK. |
| `demo/puppeteer/**` | Reliable upload/document/public-page E2E assertions. |
| `tests/**` | Regression coverage for all remediations. |
| `docs/release/**`, `docs/architecture/**` | Closure, traceability, evidence, readiness, retention documentation. |

The working tree already contained the in-progress R2 patch set when acceptance began; unrelated user changes were preserved.

## 12. Database changes

| Migration / constraint / index | Reason | Rollback / forward-fix | Verification |
|---|---|---|---|
| `2026_07_18_000002_create_passport_validation_evidence` | Persist immutable evaluation runs and per-rule results. | Down removes triggers/tables in FK-safe order. | Fresh migration + evidence/immutability tests. |
| Validation evidence immutability triggers | Prevent update/delete of audit evidence. | Removed only by migration rollback. | MySQL trigger tests. |
| `2026_07_19_000001_enforce_passport_enabled_languages_array` | Repair legacy double JSON and enforce valid language arrays. | Forward migration normalizes data before adding CHECK; rollback drops CHECK only. | Fresh migration, model and constraint tests. |
| `chk_product_passports_enabled_languages` | Require JSON array, non-empty, containing default language. | Named constraint supports deterministic rollback. | Direct invalid-write tests. |

All 37 migrations report `Ran` on the final clean MySQL database.

## 13. Security verification

| Area | Result |
|---|---|
| Authorization | Company permission gates/policies exercised across catalog, passport, readiness, assets, and publication. |
| Tenant isolation | Foreign UUIDs return generic failure/404; mixed bulk requests are atomic; preview is company-scoped. |
| CSRF | Stateful write routes remain under Laravel web CSRF middleware; browser document sync uses the page CSRF token. |
| Open redirect | Product return target is validated and omitted when absent. |
| Mass assignment | Controllers use validated request DTO/arrays and action-level ownership checks. |
| Cross-tenant tests | Full suite covers cross-company catalog, passport, document, media, preview, and bulk cases. |
| Public surface | Resolver reads immutable published snapshots only; public assets are snapshot-scoped; no admin links. |
| Dependency advisories | npm: 0 vulnerabilities. Composer lock: online audit passed with no advisories; later repetition was network-isolation-only. |

## 14. Automated test evidence

| Command | Exit | Tests | Assertions | Failures | Skipped | Duration |
|---|---:|---:|---:|---:|---:|---:|
| `php artisan test --compact --log-junit ...` | 0 | 2,073 | 7,794 | 0 | 1 | 474.6 s |
| `vendor/bin/pint --test` | 0 | n/a | n/a | 0 | 0 | 16.3 s |
| `vendor/bin/phpstan analyse --no-progress` | 0 | n/a | n/a | 0 errors | 0 | 37.6 s |
| `npm run build` | 0 | Vite build | n/a | 0 | 0 | 9.8 s |
| `composer validate --strict` | 0 | manifest/lock | n/a | 0 | 0 | 6.3 s |
| `npm audit --offline` | 0 | dependency tree | n/a | 0 vulnerabilities | 0 | 4.6 s |
| `composer audit --locked` | 0 | lock advisories | n/a | 0 advisories | 0 | completed online during acceptance |

JUnit: `docs/release/evidence/R2_FINAL_JUNIT.xml`. `tests/Integration` is absent; this is not an unexecuted suite because repository integration scenarios reside under `tests/Feature`.

## 15. Manual E2E evidence

The final scenario used a fresh deterministic suffix and real UI/API boundaries:

1. Logged in as the demo company owner.
2. Created root and child categories.
3. Created seven attribute definitions.
4. Created a product and three variants.
5. Uploaded four images and selected the primary image.
6. Uploaded three real PDF fixtures with valid domain enum values and certificate metadata.
7. Created the DPP and filled nine sections.
8. Attached all three document UUIDs to draft revision 11 through the authenticated web endpoint.
9. Published after readiness re-evaluation.
10. Verified stable QR/public URL.
11. Opened the public page in an incognito context and required name, image, description, manufacturer, no admin UI, and document links.
12. Verified audit log access.

Result: `DEMO-20260719-104351`, 45 passed, 0 failed. Public page had 3 media links and 3 document links. A direct anonymous document request returned HTTP 200, `application/pdf`, 589 bytes, and `Cache-Control: public, max-age=31536000, immutable`.

## 16. Browser evidence

| Page | Action | Expected | Actual | Screenshot/evidence | Console / network errors |
|---|---|---|---|---|---|
| Dashboard | Authenticated owner visit | Correct company/role | NordiPass Demo AB / owner | `evidence/R2_BROWSER_01_DASHBOARD.png` | None observed |
| Traffic signals readiness | Open deterministic equivalent | Exact explainable evaluation | 66%, 277/421, 8/21/1 failed, 34 passed, 2 N/A | Recorded acceptance DOM/evidence above | None observed |
| Reflective Safety Vest readiness | Open seeded record | Explainable grouped UI | 80%, 336/421, 3/18/1 failed, 42 passed, 2 N/A | `evidence/R2_BROWSER_02_READINESS.png` | None observed |
| Draft preview | Desktop and 390×844 | Authenticated, banner, no public mutation, responsive | Banner/product/documents present; 390 inner width, 375 scroll width | Feature tests + manual DOM | None observed |
| Published passport | Incognito desktop/mobile | Public immutable snapshot and assets | 3 media, 3 documents, canonical, JSON-LD, no admin links | `report.html`, `report.json` | None observed |
| Public document | Anonymous direct GET | Download immutable PDF | 200 `application/pdf`, immutable cache | CLI HTTP evidence | None observed |

## 17. Self-audit

| Finding | Risk | Fix | Re-verification |
|---|---|---|---|
| Stale readiness rule-count expectations | False acceptance math | Updated tests and documented 66-rule registry | Unit + full suite |
| Browser upload reported success after validation redirect | False-positive E2E | Assert redirect UUID and valid enum metadata | Final 45/45 run |
| Public document presence was optional in E2E | Missing public assets could ship | Make documents mandatory and attach them to draft | Incognito public verification |
| Initial Pint retry found 3 formatting deltas | Style gate failure | Applied Pint | Pint re-run passed |
| Temporary acceptance servers/logs/caches | Dirty workspace/process leakage | Stopped scoped processes and removed temporary files | Final status review |
| Debug scan matched `Yaml::dump` | Potential false alarm | Inspected matches: legitimate Symfony YAML serialization | No debug helpers/TODO/FIXME remain |

## 18. Residual risks

No known R2 release-blocking residual risks.

## 19. Git diff summary

- Baseline branch: `master`; HEAD `ba3b0d2eecf6564c22cbf798f8c5a88f9f3744f2`; origin delta at start: 0 ahead / 0 behind.
- Final patch scope is limited to R2 catalog/passport/readiness/publication/public-page/E2E/tests/docs and intentional migration/evidence additions.
- Initial worktree was already dirty; it was preserved and not reset, staged, committed, or pushed.
- `git diff --check` passes. No secrets were added. Precise scans found no TODO/FIXME/debug helpers; `Yaml::dump` test serialization is legitimate.
- Acceptance-only server logs, query script, caches, and baseline JUnit were removed. Remaining untracked source/migration/docs/evidence paths are intentional R2 deliverables, not accidental runtime artifacts.

## 20. R3 handoff

```text
R2 formally closed.
R3 may begin from R3.1.
```
