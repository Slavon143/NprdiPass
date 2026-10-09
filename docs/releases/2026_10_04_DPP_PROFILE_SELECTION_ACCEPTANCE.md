# DPP profile selection acceptance — 2026-10-04

This pass follows the source metadata contract work. It verifies local category/profile selection and repairs three confirmed configuration boundaries. It is not staging or authenticated supplier acceptance and introduces no regulatory profile catalogue or legal claims.

## Confirmed problems and fixes

1. `DppProfileResolver::resolve` and `ProductReadinessProfileResolver::resolve` silently fell through when only one part of a persisted product override was present. The normal override setter already rejects incomplete pairs, so this is a defence against invalid persisted configuration rather than a demonstrated ordinary HTTP mutation bypass. Shared `assertCompleteSelection` now rejects a missing/empty key or missing/nonpositive version. Explicit higher-priority assignments retain their precedence.
2. `ReadinessProfileRepository::for` made an exception for an unknown key/version when it matched the configured fallback: it built a definition from global rules instead of rejecting the unknown version. This could label operational rules as a nonexistent sector profile. That fabrication path is removed. `DppProfileResolver` also validates an absent persisted fallback through the repository. Known config-only legacy profiles remain supported, but unknown fallback configuration fails.
3. `DppProfileResolver` previously filtered/dropped an inactive persisted fallback. Config-only compatibility could then reactivate that profile despite catalogue lifecycle status. Direct and batched resolution now retain the exact stored row and reject it through the ordinary applicability checks.

Existing frozen snapshots, stored validation runs and published evidence are not backfilled or rewritten. Historically registered deprecated versions remain resolvable through the repository; active selection still observes profile dates/status.

## Executed domain path

The regression creates two explicitly synthetic independent profiles with distinct required evidence requirements, two tenant categories and active templates through the real taxonomy actions. A two-row CSV maps category and creates products through actual preview/apply. It verifies both direct and page-batch profile resolution, then creates drafts, builds readiness, evaluates each distinct requirement and records validation runs.

Missing handlers deliberately produce failed blockers rather than a ready result. These fixtures prove independent requirement routing, not a successful regulatory publication. Changing live requirements and product category after draft creation leaves the frozen profile/required level intact and the reread validation evidence unchanged.

The final fixture also uses current commercial company creation: imported products are unmanaged and are enrolled through `SetProductManagedAction` before draft creation. Managed-product admission tests are executed alongside it. This is a small correctness fixture, not a scale benchmark.

Initial reproducer logs are retained: incomplete overrides and a misspelled fallback failed their expected rejection assertions. The first integration helper also compared in-memory model attributes with reread MySQL JSON; normalization/order caused a harness comparison failure. The corrected assertion compares reread evidence before and after, without modifying stored results.

## Verification

Final expanded verification passed **234 tests / 1689 assertions**, covering DPP unit tests, readiness repository/evaluation/history, taxonomy, publication, commercial admission and all new selection boundaries. Earlier adjacent verification passed 223 tests / 1658 assertions; boundary-only checks with active-window cases passed 13 tests / 35 assertions; the commercial fixture/admission check passed 19 tests / 60 assertions. Runs overlap and are not additive unique test counts. Final PHPStan reports zero errors; Pint and `git diff --check` pass. This does not replace the qualified historical complete-suite record.

Local logs reside in the commercial acceptance directory recorded in the companion acceptance:

- `dpp-selection-before.log`, `dpp-selection-focused.log`.
- `dpp-selection-regressions.log`, `dpp-selection-final-focused.log`, `dpp-selection-commercial-focused.log`.
- `dpp-selection-final-regressions.log` (test discovery rejected an overlapping file/directory argument before running tests), `dpp-selection-final-regressions-clean.log` (corrected nonoverlapping arguments).
- `dpp-selection-final-analysis.log`, `dpp-selection-final-lint.log`.

All database work uses `nordipass_testing`. No normal tenant data, supplied XLSX files, external providers or production publications were changed. No commits or deployment occurred.

## Remaining applicability limits

The actual algorithm prioritizes explicit assignments, product override, primary-category active template and exact configured fallback. It validates lifecycle status and effective dates. Jurisdiction/product_scope metadata does not automatically classify a product or establish legally applicable sector coverage; that needs explicit domain policy, maintained profile versions and verified supplier/category assignments. Do not infer it from these synthetic tests. No complete ESPR or other legal certification is claimed.

Authenticated supplier files, representative real category/profile mappings and actual staging operations remain release gates. Other open gates remain in `2026_10_04_RELEASE_GATES.md`, including target worker/recovery/backup rehearsal and the separate development dependency advisory.
