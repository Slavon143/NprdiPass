# R2 Catalog → Passport Integrity Audit

Date: 2026-07-18

## Scope

Audit target: Product catalog data flowing into Product Passport draft readiness, publication, immutable snapshot/assets, and public QR/passport delivery.

Primary chain:

Company → Category → Product → Product Variant → Attributes → Media → Documents → Passport Schema → Passport Sections → Draft Passport → Draft Revision → Readiness Validation → Generated Payload → Published Passport Version → QR/Public Passport

## Findings

### Readiness score

The readiness score is weighted, not a simple count of passed rules.

Configured weights:

- Blocker: 10
- Warning: 3
- Recommendation: 1

Formula:

`passed weighted points / applicable weighted points * 100`

Rules with `not_applicable` status are excluded from the denominator. A screen such as `Not ready — 59%` with many blockers/warnings can therefore be correct even when the raw rule count looks different.

The readiness and publish-confirm screens now display a score breakdown so the reason for values like `59%` is visible.

### Documents count

`Documents Count` on publish-confirm is the count of draft `document_references`. If it shows `0`, the passport draft has no linked product document references yet. Existing catalog documents are not automatically counted unless referenced by the draft.

### Media assets count

`Media Assets Count` on publish-confirm is the count of draft `media_references`. Published snapshots still include catalog context media from the product when available; the confirm counter describes explicit draft references.

### Enabled sections

There are 11 DPP section keys in `DppSectionKey`. New drafts enable all 11 sections by default, so `Enabled Sections: 11` is expected.

### Draft revision and version number

Current design stores one mutable draft row with a `draft_revision` counter. On publish, that draft row becomes the immutable published version and a new draft row is created with the next revision.

`Estimated Version: Version 1` means no published version exists yet; the first publish will allocate version number 1.

### Publish gate

Publication re-evaluates readiness server-side inside `PublishProductPassport`. `not_ready` is blocked. `ready_with_warnings` requires explicit warning acknowledgement.

### Public passport / QR

The public route resolves the passport by immutable `public_id` and serves the current published version. Public assets are served from copied immutable `passport_assets`, not from live catalog storage.

## Fixed defects

1. Passport snapshot document resolution was too broad: it could resolve document UUIDs/version UUIDs globally. It now resolves only active documents belonging to the same `company_id` and `product_id`.
2. Document passport assets were being stored as `product_media` kind. The model, MySQL check constraint, factory, and publication action now support a dedicated `document` kind.
3. Readiness/publish-confirm UI did not explain the weighted score. A shared score-breakdown partial now shows passed points, failed blocker/warning/recommendation points, and the first failed rules with navigation links.
4. Readiness API now returns the same `score_breakdown` data for frontend/debug consumers.

## Verification

Static verification completed:

- PHP syntax check passed for changed PHP files.
- Blade templates compiled successfully with `php artisan view:cache`.
- `git diff --check` reported no whitespace/conflict-marker issues.

Targeted MySQL test command attempted:

```bash
php artisan test tests\Unit\Passports\ProductPassportEnumTest.php tests\Feature\Passports\Schema\ProductPassportSchemaTest.php tests\Feature\Passports\Publication\DocumentContractTest.php tests\Feature\Passports\Public\PublicPassportImmutableAssetTest.php --stop-on-failure
```

The command could not execute in this Codex PHP environment because `pdo_mysql` is not installed: `could not find driver`. The project is still configured to use MySQL for tests (`Connection: mysql`, `Database: nordipass_testing`).
