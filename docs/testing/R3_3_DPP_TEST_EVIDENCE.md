# R3.3 DPP Test Evidence

## Verdict

```text
R3_3_ACCEPTED
```

R3.3 is accepted. The dependency audit gate was completed against the public npm and Composer advisory registries with approval for dependency metadata disclosure, browser/responsive/accessibility evidence passed across the required viewports, and the fresh-install plus full regression gates are clean.

## Baseline

| Item | Value |
|---|---|
| Starting commit | `6e05757682da862e4b00f5de5bb444cbc7f6e96b` |
| Branch | `master` |
| Baseline `git diff --check` | Passed |
| Baseline migration count | 37 |
| Database engine | MySQL `8.0.46` |

## Fresh Install Evidence

| Check | Evidence |
|---|---|
| Cache clear | `php artisan config:clear`, `cache:clear`, `route:clear`, `view:clear` passed |
| Fresh migration | `php artisan migrate:fresh --env=testing --force` passed |
| Migration status | `php artisan migrate:status --env=testing` showed all 37 migrations ran |
| Required seeder | `php artisan db:seed --env=testing --force` ran `RolePermissionSeeder` successfully |
| Route inventory | `(php artisan route:list --json \| ConvertFrom-Json).Count` returned `220` |

## Upgrade And Backfill Evidence

| Check | Evidence |
|---|---|
| R3.2 payload validity | `ProductPassportAllSectionsSaveTest::test_r3_2_payload_shape_remains_valid_and_backfill_is_not_applicable` passed |
| Old optional-field absence | Same test verifies new advanced fields are not invented during validation/normalization |
| Historical revision rewrite | Same test writes normalized old-shape payload without changing draft revision |
| Historical readiness arithmetic | `ReadinessAcceptanceFixturesTest` passed with canonical R2/R3.2 arithmetic |
| Backfill decision | `Not Applicable` |

Backfill is not applicable because R3.3 adds optional JSON schema fields only. It adds no migrations, no new required derived fields, no new table/column/index, and no legacy field transformation. Published snapshots and historical revisions remain immutable and are not rewritten at runtime.

## Section Matrix

| Section | Draft | Validation | Readiness | Preview/Publish | Public | API |
|---|---|---|---|---|---|---|
| Materials | Automated | Automated | Automated | Automated | Automated | Automated |
| Recycled Content | Automated | Automated | Automated | Automated | Automated | Automated |
| Environmental Metrics | Automated | Automated | Automated | Automated | Automated | Automated |
| Environmental Claims | Automated | Automated | Automated | Automated | Automated | Automated |
| Usage | Automated | Automated | Automated | Automated | Automated | Automated |
| Care | Automated | Automated | Automated | Automated | Automated | Automated |
| Repair | Automated | Automated | Automated | Automated | Automated | Automated |
| Repairability | Automated | Automated | Automated | Automated | Automated | Automated |
| Spare Parts | Automated | Automated | Automated | Automated | Automated | Automated |
| Recycling | Automated | Automated | Automated | Automated | Automated | Automated |
| Take-back | Automated | Automated | Automated | Automated | Automated | Automated |
| Warranty | Automated | Automated | Automated | Automated | Automated | Automated |
| Support | Automated | Automated | Automated | Automated | Automated | Automated |
| Responsible Operator | Automated | Automated | Automated | Automated | Automated | Automated |
| Compliance Metadata | Automated | Automated | Automated | Automated | Automated | Automated |

The matrix is backed by automated regression plus browser evidence across `375x667`, `768x1024`, and `1280x720`.

## Audit Evidence

R3.3 follows the canonical R2 audit contract: `passport.draft.updated` with section metadata, not duplicate section-specific event names. The event payload includes product UUID, passport UUID, draft version UUID, old revision, new revision, `section_key`, and `changed_sections`. Tests assert that full payload data, section data, and document content are not stored.

| Action | Event | Test |
|---|---|---|
| Materials update | `passport.draft.updated` with `changed_sections=["materials_and_composition"]` | `DppAuditTest` provider |
| Environment update | `passport.draft.updated` with `changed_sections=["environmental_information"]` | `DppAuditTest` provider |
| Usage/care update | `passport.draft.updated` with `changed_sections=["usage_and_care"]` | `DppAuditTest` provider |
| Repair update | `passport.draft.updated` with `changed_sections=["repair_and_spare_parts"]` | `DppAuditTest` provider |
| Recycling update | `passport.draft.updated` with `changed_sections=["recycling_and_disposal"]` | `DppAuditTest` provider |
| Warranty/support update | `passport.draft.updated` with `changed_sections=["support_and_contact"]` | `DppAuditTest` provider |
| Responsible operator update | `passport.draft.updated` with `changed_sections=["manufacturer_and_operator"]` | `DppAuditTest` provider |
| Compliance metadata update | `passport.draft.updated` with `changed_sections=["certifications_and_documents"]` | `DppAuditTest` provider |

## Publication Immutability

`ProductPassportAllSectionsSaveTest::test_advanced_version_one_snapshot_remains_immutable_after_version_two` publishes V1 with advanced sections, records the canonical payload hash, edits materials, environmental metrics, repair, warranty/support, and responsible operator data, publishes V2, and asserts:

- V1 payload unchanged;
- V1 hash unchanged;
- V2 hash differs;
- expected V1/V2 material and operator values remain distinct.

## Payload Limits

| Limit | Evidence |
|---|---|
| Encoded payload size | `DppPayloadValidator::MAX_ENCODED_SIZE` = 1 MiB; tested |
| Locale count | `DppPayloadValidator::MAX_LOCALE_COUNT` = 20; tested |
| String-list item count | Field definitions use `maxItems`; tested |
| Material count | `materials` max 100; tested |
| JSON-list item count | Advanced JSON-list field definitions use `maxItems`; tested |
| Structured list string length | `DppPayloadValidator::MAX_JSON_LIST_STRING_LENGTH` = 2000; tested |
| Document references | Max 100; tested |

## Automated Evidence

| Command | Result | Tests | Assertions | Duration |
|---|---:|---:|---:|---:|
| `php artisan test tests\Unit\Passports\Dpp` | Pass | 101 | 799 | 14.257s |
| `php artisan test tests\Feature\Passports\Audit\DppAuditTest.php` | Pass | 17 | 98 | 12.290s |
| `php artisan test tests\Feature\Passports\Authoring\ProductPassportAllSectionsSaveTest.php` | Pass | 6 | 165 | 27.756s |
| `php artisan test tests\Feature\Passports\Readiness\ReadinessAcceptanceFixturesTest.php` | Pass | 1 | 35 | 11.909s |
| `php artisan test tests\Feature\Passports` | Pass | 501 | 1562 | 105.550s |
| `php artisan test tests\Feature\Api` | Pass | 285 | 846 | 33.634s |
| `php artisan test tests\Unit` | Pass | 370 | 1899 | 21.243s |
| `php artisan test tests\Integration` | Not applicable | n/a | n/a | Path does not exist |
| `php artisan test` | Pass | 2096 | 8211 | 398.173s |
| `vendor\bin\pint --test` | Pass | n/a | n/a | n/a |
| `vendor\bin\phpstan analyse` | Pass | n/a | n/a | 0 errors |
| `composer validate --strict` | Pass | n/a | n/a | n/a |
| `composer check-platform-reqs` | Pass | n/a | n/a | n/a |
| `npm ci --ignore-scripts` | Pass | n/a | n/a | 203 packages installed |
| `npm run build` | Pass | n/a | n/a | Vite build passed, 3.86s |
| `php artisan config:cache --env=testing` | Pass | n/a | n/a | Passed; browser run used cached local testing config with file sessions |
| `php artisan route:cache` | Pass | n/a | n/a | n/a |
| `php artisan view:cache` | Pass | n/a | n/a | n/a |
| `php artisan nordipass:readiness-profile nordipass-pilot 1` | Pass | n/a | n/a | 66 rules, fingerprint unchanged |

## Registry Audit Evidence

| Command | Result |
|---|---|
| `npm audit --json` | Pass; public npm advisory request approved; `vulnerabilities` empty, total vulnerabilities `0` |
| `composer audit --format=json` | Pass; Packagist/advisory request approved; `advisories: []`, `abandoned: []` |

No dependency-audit waiver is used for this verification pass.

## Skipped Test Classification

The full suite reported one skipped test. Repository skip sites are:

| File | Reason | R3.3 relevance |
|---|---|---|
| `tests\Feature\Infrastructure\DeployCheckDetailedTest.php` | `Config file not found.` | Not Advanced DPP, readiness, publication, public Passport, API, tenant isolation, or security |
| `tests\Feature\Infrastructure\BackupDetailedTest.php` | `No database artifact in backup` | Not Advanced DPP, readiness, publication, public Passport, API, tenant isolation, or security |

Both are infrastructure evidence limitations. No R3.3-critical skipped test was found.

## Browser And Accessibility Evidence

Browser E2E evidence was generated with `node demo\puppeteer\r3_3_acceptance.js` against `http://127.0.0.1:8765` after seeding the NordiPass showcase data.

| Evidence | Result |
|---|---|
| Report | `demo\puppeteer\output\r3_3_acceptance\report.json` |
| Final run | `2026-07-22T15:08:01.482Z` to `2026-07-22T15:08:43.759Z` |
| Product fixture | `Industrial LED Work Lamp 40 W` |
| Viewports | `375x667`, `768x1024`, `1280x720` |
| Pages | Advanced editor, readiness, preview, publish confirmation, published version, public passport |
| Console warnings/errors | None |
| Failed requests | None |
| Horizontal overflow | None |
| Screenshots | Mobile, tablet, and desktop screenshots saved under `demo\puppeteer\output\r3_3_acceptance` |
| Input labels | `labelsMissing: []` |
| Keyboard focus | Sampled tab order had visible focus for all sampled controls |
| Dynamic material rows | Add focuses `New material name`; remove button has `Remove new material row`; removal returns focus to a material input |
| Validation accessibility | Error summary receives focus, includes link to `#field-materials_and_composition-materials`, and sets one invalid field |

## Final Evidence Status

R3.3 evidence now includes approved public registry audits, fresh MySQL install, compatibility, backfill N/A, audit metadata, payload bounds, public/API serialization, immutability coverage, full PHP regression, static gates, Vite build, cache compilation, readiness profile verification, and responsive browser/accessibility evidence. The stage is:

```text
R3_3_ACCEPTED
```
