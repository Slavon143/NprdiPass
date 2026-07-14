# NordiPass R1.8 — Product and Variant Media

**Database:** MySQL 8 only  
**Storage:** private authenticated delivery only

## Scope

R1.8 implements Product-level and Variant-level images, upload, metadata editing, primary selection, full-set reorder, soft deletion, physical cleanup, audit, Blade galleries, detail/index integration, and deterministic local demo media. Documents, PDF, SVG, GIF, AVIF, video, public URLs, CDN, transformations, thumbnails, API endpoints, and lifecycle/publication rules are deferred.

## Formats and validation

Only JPEG (`image/jpeg`), PNG (`image/png`), and WEBP (`image/webp`) are supported. `ImageUploadValidator` requires a successful non-empty upload; a maximum 10 MB file; matching finfo, image-header, declared MIME, and extension; positive dimensions no larger than 12,000 × 12,000; at most 40 million pixels; and a server-computed lowercase SHA-256. SVG and renamed/non-image files are rejected. Width, height, MIME, size, and checksum always come from file content, never request fields.

Alt text and caption are trimmed plain text, empty values become null, and both follow the existing 500-character schema/domain limit. Blade escapes both. Original filenames are stripped of path components/control characters, bounded to 255 characters, and never used for paths or authorization.

## Storage and delivery

The `catalog_media` local disk is private, uses throwing writes, has no public URL, and defaults to `storage/app/catalog-media`. Tests use an explicitly marked root below `storage/framework/testing/disks`; cleanup deletion refuses a non-test root while `APP_ENV=testing`.

Paths are server-generated:

- Product: `{company_uuid}/products/{product_uuid}/{media_uuid}.{extension}`
- Variant: `{company_uuid}/products/{product_uuid}/variants/{variant_uuid}/{media_uuid}.{extension}`

`MediaPathGuard` rejects empty/dot segments, traversal, absolute/drive/UNC/backslash/null-byte paths and verifies real local containment to prevent symlink escape. `catalog.media.content` requires the normal auth/verified/current active Company middleware plus `catalog.view`, tenant-resolves a non-deleted row, verifies containment/existence, and streams inline with stored `Content-Type`, `Content-Length`, private cache control, quoted SHA-256 ETag, and `nosniff`. Missing files are logged without a path and return 404.

## Ownership and primary rules

Product-level media has `product_variant_id = NULL`; Variant media carries the same Company/Product plus its Variant. Product images can never enter Variant operations and vice versa. R1.2 composite MySQL foreign keys remain the last same-Company/same-owner protection.

The only primary sources of truth are `products.primary_media_id` and `product_variants.primary_media_id`; there is no `is_primary`. The first image in a scope becomes primary. Later images do so only with `make_primary` or explicit set-primary. Product primary accepts only Product-level media; Variant primary accepts only that Variant's media. Owner and media rows are locked. Re-selecting the current primary performs no update/audit. Deletion clears the pointer and does not auto-promote another image; Product media may only be a visual fallback on the Variant page.

The Product aggregate limit is 50 active images total, including Variant images; each Variant is additionally limited to 10. Product and Variant owner locks serialize the fresh count.

## Mutation lifecycle

Upload validates and hashes the temporary file, creates a UUID/path, writes the private final file, then locks/reloads owners in a MySQL transaction, rechecks authorization/limits, inserts `product_media`, optionally updates primary, and writes audit. Any database/audit failure triggers compensating file deletion; an unexpected process interruption is handled by orphan cleanup.

Metadata update changes only `alt_text`, `caption`, and `sort_order`. Reorder requires the complete exact active owner-scoped UUID set with no duplicates and assigns deterministic increments of ten. Exact metadata, primary, and reorder repeats are no-ops without duplicate audit.

Delete locks/reloads owners and media, clears only the applicable primary pointer, soft-deletes the row, and writes audit in one transaction. Physical deletion is attempted after that mutation commits. Failure leaves the soft-deleted row and file for retry; no restore route or force-delete exists.

## Cleanup

`php artisan catalog:prune-orphan-media` is dry-run. Real deletion requires `--delete`; `--older-than=24` and `--limit=500` are safe defaults. An orphan is an old file on the configured disk with no active or soft-deleted row. Active/known/new files are preserved. Old files for soft-deleted rows form a separate retry class; successful cleanup retains row metadata. Unsafe paths are skipped. Repeated runs are idempotent.

## Routes and permissions

Product and Variant galleries expose GET index, POST upload, PATCH metadata, POST set-primary, PATCH reorder, and DELETE delete routes under their nested owner context. Content is `GET /catalog/media/{media}/content`. All use the existing web middleware. Viewer may view through `catalog.view`; Owner/Admin/Editor manage through `catalog.manage_media`; Viewer mutation controls are hidden and backend denial remains authoritative. Wrong-tenant, wrong-Product, and wrong-Variant identifiers are concealed as 404 by scoped controller resolution.

## Audit and logging

Events are `catalog.media.uploaded`, `updated`, `primary_changed`, `reordered`, and `deleted`. Metadata contains owner/media UUIDs, dimensions, byte size, MIME, a short checksum prefix, changed field names, counts, and old/new primary UUIDs as applicable. It never contains file bytes, storage paths, raw requests, credentials, tokens, or full original filenames. Structured failure logs contain safe UUID context, operation, and error code only.

## Demo media and tests

`CatalogDemoSeeder` creates nine deterministic embedded images only for `NordiPass Demo AB`: four for gloves (two Product, Medium Variant, Large Variant), two for the vest (one Product, Yellow/Large Variant), and three for the lamp (two Product, 40 W Variant). It uses the private disk, real JPEG/PNG/WEBP bytes, stable logical filenames, ownership-safe primary pointers, no audit, production refusal, and idempotent row/path reuse.

Media tests use MySQL `nordipass_testing` and isolated private storage. The bootstrap rejects SQLite/non-MySQL and unsafe database names. CI runs focused Media tests, full Catalog tests, and the full application suite. Database guarantees cover Company/Product/Variant ownership, primary-pointer owner tuples, positive dimensions/non-negative size, and checksum shape. Application guarantees cover actual MIME/header/file existence, limits, private paths, Product-level null scope, authenticated delivery, compensation, locking, cleanup retry, and audit.
