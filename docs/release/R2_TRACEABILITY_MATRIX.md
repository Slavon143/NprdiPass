# R2 acceptance traceability matrix

Status values are based on the final MySQL suite, browser run `DEMO-20260719-104351`, code/migration inspection, and the linked closure documents.

| ID | Requirement | Primary implementation/source | Verification evidence | Status |
|---:|---|---|---|---|
| 1 | Category relationships correct | Category model/migrations/actions | Category hierarchy + delete tests | Pass |
| 2 | Products tenant-safe | Product scopes/controllers/actions | Cross-company catalog Feature tests | Pass |
| 3 | Variants tenant-safe | Variant scopes/controllers/actions | Variant API/Web tenant tests | Pass |
| 4 | Default variant always valid | Product/variant constraints and lifecycle actions | Variant/lifecycle/constraint tests | Pass |
| 5 | Attributes/options integral | Attribute/value/option schema and actions | Attribute schema/API/delete tests | Pass |
| 6 | Product list actions supported | Product index controller/view | Product index/filter/action tests | Pass |
| 7 | Bulk product deletion/archive safe | `BulkArchiveProductsAction`, bulk status action | Mixed own/foreign/unknown regression tests | Pass |
| 8 | Back navigation preserves context | `ProductIndexReturnUrl`, product controller/views | `ProductBackNavigationTest` | Pass |
| 9 | Dependent category not deleted | `DeleteCategoryAction` | Active + archived dependency tests | Pass |
| 10 | Unused category deletes | Category delete action | Category happy-path test | Pass |
| 11 | Used attribute not deleted | `DeleteAttributeAction` | Product/variant + archived value tests | Pass |
| 12 | Unused attribute deletes | Attribute delete action | Attribute happy-path test | Pass |
| 13 | Default variant never dangling | FK/deferred constraints + lifecycle checks | Schema/lifecycle tests | Pass |
| 14 | Product deletion preserves published passport | Passport lifecycle/version retention | Publication/snapshot tests | Pass |
| 15 | Published media/documents retained | Publication assets + pinned document versions | Immutable asset/document contract tests | Pass |
| 16 | Restore checks invariants | Catalog/passport restore actions | Restore Feature tests | Pass |
| 17 | Bulk operations atomic | Bulk category/attribute/product actions | Mixed-tenant cardinality regression tests | Pass |
| 18 | Draft and published snapshot separated | Passport current draft/published pointers | Authoring/public isolation tests | Pass |
| 19 | Revision immutable | Published version model + MySQL triggers | Immutability tests | Pass |
| 20 | Readiness uses exact revision | Context builder + validation run identity | Evidence/revision/concurrency tests | Pass |
| 21 | Generation deterministic | Normalizer/snapshot builder | Snapshot integrity tests | Pass |
| 22 | Published snapshot immutable | Version/assets/evidence triggers and model guards | Immutability suites | Pass |
| 23 | Versioning correct | Publication/version actions | Version history/publication tests | Pass |
| 24 | Publication atomic | `PublishProductPassport` transaction + row lock | Concurrency/idempotency tests | Pass |
| 25 | Backend blockers prevent publish | Evaluator + publish action | Publication blocker tests + browser disabled action | Pass |
| 26 | Warning policy correct | Readiness severity policy | Readiness unit/publication tests | Pass |
| 27 | QR targets correct resolver | QR routes/service + stable public ID | QR tests + browser | Pass |
| 28 | Public passport needs no admin session | Public route/resolver/layout | Incognito E2E and public tests | Pass |
| 29 | Cross-tenant access impossible | Company scopes/permission gates | Catalog/passport/media/document/preview tenant tests | Pass |
| 30 | Weights confirmed | `config/passport_readiness.php` | Score calculator tests | Pass |
| 31 | Formula confirmed | `ReadinessScoreCalculator` | Weighted/N/A/rounding tests | Pass |
| 32 | Points and rule counts distinct | Score breakdown DTO/API/UI | Unit/API/Web tests + browser | Pass |
| 33 | Profile version stored | Validation run migration/model/action | Evidence schema/persistence tests | Pass |
| 34 | Weight snapshot stored | Validation run `weights` JSON | Evidence tests | Pass |
| 35 | Old reports reproducible | Immutable run/results and published evidence identity | Evidence immutability/history tests | Pass |
| 36 | UI understandable | Readiness presenter/lang/Blade | Web tests + manual inspection | Pass |
| 37 | Rule codes technical only | Readiness Blade debug gate | Production-mode Web tests | Pass |
| 38 | Legal disclaimer present | Public layout/readiness/public page views | Web tests + manual public page | Pass |
| 39 | Traffic signals diagnosed | Deterministic equivalent fixture | 66%, 277/421, 34/8/21/1/2 | Pass |
| 40 | Reflective Safety Vest diagnosed | Demo seed + readiness page | 80%, 336/421, 42/3/18/1/2 | Pass |
| 41 | Authorization verified | Policies/CompanyAuthorizer/permission enum | Full authorization tests | Pass |
| 42 | Tenant isolation verified | Company scopes and pre-mutation cardinality checks | Cross-tenant full suite | Pass |
| 43 | CSRF verified | Laravel web middleware + tokenized requests | Web tests and real browser writes | Pass |
| 44 | Audit verified | Activity log/audit UI | Audit tests + E2E audit step | Pass |
| 45 | Jobs safe | Queue config/jobs/scheduled maintenance | Job tests, schedule list, no failed jobs | Pass |
| 46 | Race conditions verified | Publication lock, optimistic draft revision | Concurrency and conflict tests | Pass |
| 47 | Full MySQL suite passes | PHPUnit/Pest on MySQL 8 | 2,073 tests, 7,794 assertions, 0 failures/errors | Pass |
| 48 | Pint passes | Laravel Pint | Final `--test` exit 0 | Pass |
| 49 | PHPStan passes | PHPStan configuration | Final analysis: 0 errors | Pass |
| 50 | Frontend build passes | Vite assets | Final production build exit 0 | Pass |
| 51 | Composer audit performed | `composer.lock` | Online audit: no advisories | Pass |
| 52 | npm audit performed | `package-lock.json` | Offline audit: 0 vulnerabilities | Pass |
| 53 | Manual E2E passes | Existing Puppeteer runner, strengthened assertions | 45/45 final run | Pass |
| 54 | Deletion E2E passes | Catalog actions/UI + focused/full suites | Category/attribute/product lifecycle verification | Pass |
| 55 | No blocker/major defects | Defect ledger and final gates | All discovered blockers remediated | Pass |
| 56 | No TODO/debug code | Scoped `rg` scan | Only legitimate `Yaml::dump` serializer matches | Pass |
| 57 | Final clean run successful | Fresh DB, full suite, quality and ops gates | JUnit + CLI evidence | Pass |
| 58 | Evidence report complete | Five closure docs + XML/PNG/JSON/HTML | `docs/release`, `docs/architecture`, root reports | Pass |

## Source map

| Domain | Authoritative sources |
|---|---|
| Catalog/deletion | `app/Actions/Catalog`, catalog migrations/models/controllers, `tests/Feature/Catalog` |
| Passport authoring/publication | `app/Actions/Passports`, `app/Services/Passports`, passport models/controllers, `tests/Feature/Passports` |
| Readiness | `config/passport_readiness.php`, readiness services/rules/presenter/resources/views, readiness tests |
| Public/QR/assets | public resolver/controllers/routes/views, public/QR/asset tests |
| Security/operations | policies/authorization/middleware, infrastructure commands/config/docs/tests |
| Browser | `demo/puppeteer`, `report.json`, `report.html`, `docs/release/evidence` |

No requirement relies only on narrative documentation; every accepted row maps to executable code plus automated or observed runtime evidence.
