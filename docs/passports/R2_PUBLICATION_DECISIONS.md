# NordiPass R2 — Publication Architecture Decisions

**Stage:** R2.1
**Date:** 2026-07-16
**Status:** COMPLETE
**Parent:** R2 Publication Architecture

---

This document contains 18 Architecture Decision Records (ADR) defining the foundational architecture of the NordiPass Digital Product Passport (DPP) Publication module. Each decision is final and binding for R2 implementation stages R2.2–R2.15.

---

## R2-PUB-001: Live Catalog vs Immutable Snapshot for Public Delivery

### Context

R1 provides a live Catalog with mutable Products, Variants, Attributes, and Media. The public passport page could either query live Catalog data directly or read from an immutable published snapshot.

### Options Considered

- **A: Live Catalog queries.** Public page queries Products, Variants, Attributes, Media directly from R1 tables. Always shows current data.
- **B: Immutable snapshot.** Publication creates a complete immutable copy of all passport data. Public page reads only from the snapshot.

### Decision

**B — Immutable snapshot is mandatory.**

### Rationale

1. **Regulatory requirement:** EU DPP regulations require that a published passport represents a verified state at a point in time. Live Catalog data can change at any moment without re-verification.
2. **Auditability:** External parties (regulators, consumers, partners) must be able to reference a specific passport version and know its content is fixed.
3. **Catalog independence:** R1 Editors can freely edit Products without fear of silently changing live passports. The Catalog and Publication domains are decoupled.
4. **Rollback safety:** If a Catalog edit corrupts product data, published passports are unaffected.
5. **Performance:** Public page reads a single snapshot structure rather than joining 8+ live tables.

### Consequences

- Publication becomes an explicit, non-trivial operation (building and storing a snapshot).
- Storage increases proportionally to (number of Products × number of published versions × snapshot size).
- Catalog changes do not propagate to published passports until a new version is explicitly published.
- The snapshot schema must be versioned to support future DPP section additions.
- Snapshot payload must include all data necessary for rendering — no runtime joins to live tables.

### Rejected Alternatives

**Option A (Live Catalog)** was rejected because:
- No immutability guarantee — what a consumer sees depends on when they view it.
- Catalog editors could unknowingly break public passports.
- Cannot reference a specific historical version.
- Joining multiple normalized tables on every public page request is slower than reading a single snapshot.

---

## R2-PUB-002: One Passport per Product vs One Passport per Variant

### Context

R1 Products have mandatory Variants. A single product (e.g., "Work Glove Pro") can have multiple Variants (e.g., "Black/M", "Black/L", "Yellow/M"). The DPP could be issued at the Product level or at the Variant level.

### Options Considered

- **A: One Passport per Product.** Variant data included in the Product passport.
- **B: One Passport per Variant.** Each Variant gets its own passport, own URL, own QR.
- **C: Both (configurable).** Default is Product-level; Variant-level when specified.

### Decision

**A — One Passport per Product for R2 MVP.**

### Rationale

1. **Pilot simplicity:** Pilot Company manufactures safety equipment where the DPP information is identical across Variants (same safety standards, same materials, same recycling instructions). Variant differences are size and color, which do not change DPP content.
2. **QR granularity:** In practice, one QR code on the product packaging resolves to the product's passport. Multiple Variant-specific QRs for sizes creates packaging complexity.
3. **Regulatory alignment:** EU DPP requirements (as understood at time of writing) focus on product models, not individual SKU configurations.
4. **Future compatibility:** The Product Passport model supports including all Variant identifiers and attributes. If Variant-specific passports become required, the model can be extended without breaking existing passports.

### Consequences

- Product Passport includes: default Variant, all public Variants (identifiers, attributes, media).
- One public URL per Product.
- One QR code per Product.
- Variant selection on the public page is a display concern, not a separate passport entity.
- R1 invariant: "one Passport per Company+Product pair" is enforced at database level.

### Rejected Alternatives

**Option B (Variant passports)** was deferred to R3 because:
- Unnecessary complexity for pilot.
- Would require separate QRs for each Variant.
- Pilot Company's DPP content is identical across Variants.

**Option C (Configurable)** was rejected for R2 because:
- Adds configuration complexity.
- Both paths must be implemented and tested.
- No pilot need identified.

---

## R2-PUB-003: Stable Passport URL vs Version-Specific URL

### Context

A published passport has a public URL. When a new version is published, the URL could either always point to the latest version (stable URL) or be version-specific (different URL per version).

### Options Considered

- **A: Stable Passport URL.** `/p/{public_id}` always resolves to the current published version. Historical versions at `/p/{public_id}/versions/{version_number}`.
- **B: Version-specific URL.** Each publication gets its own URL `/p/{public_id}/v/{version_number}`. No "current" URL.
- **C: QR version-specific.** QR encodes a version-specific URL.

### Decision

**A — Stable Passport URL with optional version-specific access.**

### Rationale

1. **QR stability:** QR codes printed on product packaging or labels must work for the lifetime of the product. A stable URL ensures QR continues to resolve after passport updates.
2. **Consumer expectation:** Consumers scanning a QR expect to see current product information, not an outdated version.
3. **Printability:** QR codes are printed once. Reprinting labels for every passport update is operationally impossible.
4. **Historical access:** Previous versions are available at version-specific URLs for audit/reference purposes (if enabled by Company policy).

### Consequences

- QR encodes `/p/{public_id}` (stable URL).
- New version publication transparently updates what `/p/{public_id}` resolves to.
- Version-specific URLs available at `/p/{public_id}/versions/{version_number}`.
- Historical version public access is controlled by a Company-level policy setting (default: disabled for pilot).
- Cache invalidation must be version-aware: `/p/{public_id}` cache key includes current version ID.
- Unpublishing removes the current published pointer; `/p/{public_id}` returns 410.
- Republishing restores the pointer to the most recently published version.

### Rejected Alternatives

**Option B (Version-specific URL only)** was rejected because:
- QR codes would be permanently tied to a specific version.
- No concept of "current" — consumers see whatever version the QR points to.
- Company must reprint QRs for every passport update.

**Option C (QR version-specific)** was rejected because:
- Same printing problem as Option B.
- QR is the primary consumer access method.

---

## R2-PUB-004: Normalized Tables vs JSON Snapshot vs Hybrid Storage

### Context

The published passport version must store a complete immutable snapshot of all passport data. Three storage strategies were evaluated.

### Options Considered

- **A: Fully normalized version tables.** `product_passport_versions` with child tables for translations, assets, documents, sections. Public page queries normalized tables with joins.
- **B: Single immutable JSON snapshot.** One JSON column containing the entire passport payload. Public page deserializes JSON.
- **C: Hybrid — normalized metadata + immutable JSON payload + asset manifests.**

### Decision

**C — Hybrid storage model.**

### Rationale

1. **Query efficiency:** Normalized columns (public_id, version_number, status, published_at, company_id, product_id) support indexed queries for management UI and API. JSON blob stores the full renderable passport content.
2. **Public delivery speed:** Public page reads a single JSON column and renders it. No multi-table joins on the hot path.
3. **Schema evolution:** JSON schema can be versioned independently of relational columns. New DPP sections can be added without migrations.
4. **MySQL constraints:** Unique constraints on (company_id, product_id), (public_id), and (passport_id, version_number) are enforced at the relational level.
5. **Asset integrity:** Asset references in the JSON include checksums. The asset manifest in the JSON is the canonical list. Normalized asset tables provide management UI querying.
6. **Debuggability:** The JSON snapshot is human-readable. Support can inspect exact published content without reconstructing from multiple tables.
7. **Storage growth:** One JSON column per version is bounded (~100KB estimated). Storage cost is negligible for pilot scale.

### Storage Breakdown

| Data | Storage | Purpose |
|---|---|---|
| `product_passports` table | Normalized | Identity, ownership, lifecycle, current pointers |
| `product_passport_versions` table | Normalized metadata + JSON `snapshot_payload` column | Version management + full immutable content |
| `product_passport_assets` table | Normalized | Asset inventory, management queries, cleanup |
| `product_passport_documents` table | Normalized | Document inventory, versioning, expiration tracking |

### JSON Snapshot Logical Schema

```json
{
  "schema_version": "1.0",
  "passport_public_id": "01J...",
  "version_number": 1,
  "published_at": "2026-07-16T12:00:00.000000Z",
  "default_language": "sv",
  "available_languages": ["sv", "en"],
  "product": {
    "name": "Work Glove Pro",
    "brand": "SafeHand",
    "manufacturer": "SafeHand AB",
    "country_of_origin": "SE",
    "categories": [{"name": "Hand Protection", "slug": "hand-protection"}],
    "short_description": {"sv": "...", "en": "..."},
    "description": {"sv": "...", "en": "..."}
  },
  "variants": [
    {
      "name": "Black / M",
      "sku": "WG-BLACK-M",
      "gtin": "7312345678901",
      "mpn": "SH-WG-001",
      "is_default": true,
      "attributes": [{"name": "Color", "value": "Black"}, {"name": "Size", "value": "M"}]
    }
  ],
  "attributes": [
    {"name": "Material", "value": "Leather", "unit": null},
    {"name": "Weight", "value": "250", "unit": "g"}
  ],
  "dpp_sections": {
    "manufacturer": {"sv": {...}, "en": {...}},
    "safety_compliance": {"sv": {...}, "en": {...}},
    "usage_instructions": {"sv": {...}, "en": {...}},
    "repair_maintenance": {"sv": {...}, "en": {...}},
    "recycling_disposal": {"sv": {...}, "en": {...}}
  },
  "documents": [
    {
      "document_uuid": "01J...",
      "filename": "safety_data_sheet.pdf",
      "mime_type": "application/pdf",
      "size_bytes": 245760,
      "checksum_sha256": "abc123...",
      "language": "sv",
      "label": {"sv": "Säkerhetsdatablad", "en": "Safety Data Sheet"}
    }
  ],
  "media": [
    {
      "media_uuid": "01J...",
      "filename": "product_front.jpg",
      "mime_type": "image/jpeg",
      "size_bytes": 102400,
      "width": 1200,
      "height": 800,
      "checksum_sha256": "def456...",
      "alt_text": {"sv": "Produkt framifrån", "en": "Product front view"},
      "is_primary": true
    }
  ],
  "company": {
    "name": "SafeHand AB",
    "logo_url": null,
    "website": "https://safehand.se"
  },
  "publication_metadata": {
    "published_by_uuid": "01J...",
    "checksum_sha256": "xyz789..."
  }
}
```

### Consequences

- Management API queries normalized tables for listing, filtering, and status checks.
- Public page reads the JSON `snapshot_payload` column of the current published version.
- JSON schema versioned via `schema_version` field.
- Future DPP sections can be added by incrementing schema version and adding keys to JSON.
- Maximum snapshot payload: 1MB (application-enforced limit).
- Checksum: SHA-256 of canonical JSON (sorted keys, no whitespace).

### Rejected Alternatives

**Option A (Normalized)** was rejected because:
- Public page requires joining 5+ tables on every request.
- Schema evolution for DPP sections requires migrations.
- Harder to verify "this was exactly what was published" — must reconstruct from normalized tables.
- Slower public delivery.

**Option B (Pure JSON)** was rejected because:
- Cannot efficiently query "all passports for Company X" or "find passport by public_id" without scanning JSON.
- No referential integrity for ownership, version uniqueness.
- Asset and document management require normalized tables anyway.
- Harder to enforce tenant isolation at database level.

---

## R2-PUB-005: Draft/Version Lifecycle

### Context

The Passport has an editing lifecycle (draft) and a publication lifecycle (version). These must be clearly defined.

### Decision

### Passport Aggregate Lifecycle

```
draft → published → unpublished → archived
  ↑        │              │            │
  │        └── republish ←┘            │
  └────── restore ─────────────────────┘
```

| Status | Meaning |
|---|---|
| `draft` | Passport created, editable, not publicly visible. Zero or one draft per Passport. |
| `published` | At least one version published. Current published version available publicly. |
| `unpublished` | Was published, now hidden from public. Draft and history preserved. |
| `archived` | Passport retired. Not publicly visible. All data preserved. |

### Passport Version Lifecycle

```
draft → published → superseded
          │
          └── withdrawn (optional, only if regulatory requirement)
```

| Status | Meaning |
|---|---|
| `draft` | Version being prepared. Single draft per Passport. Mutable. |
| `published` | Immutable published version. Currently public if Passport is published. |
| `superseded` | Was published, replaced by newer version. Preserved in history. |
| `withdrawn` | Published version withdrawn for legal/regulatory reasons. Not publicly accessible. Rare. |

### Key Invariants

- One active draft version per Passport.
- One current published version per Passport (when status = published).
- A version transitions from draft to published exactly once.
- Published and superseded versions are immutable.
- Draft version is mutable until published.
- Withdrawn is a rare regulatory status; not part of normal workflow.

### Consequences

- Passport status is computed from version pointers and explicit status field.
- Archive does not delete versions — all are preserved.
- Unpublish removes the `current_version_id` pointer; versions remain.
- Restore sets status back to draft (requires re-publish).
- Draft discard is permanent (deletes draft version row).

---

## R2-PUB-006: Version Numbering

### Context

Each published version needs a version number. Numbers must be unique, monotonic, and predictable.

### Options Considered

- **A: Auto-increment per Passport.** Database sequence or `MAX(version_number) + 1`.
- **B: Timestamp-based.** `YYYYMMDDHHMMSS` or similar.
- **C: Semantic versioning.** Major.Minor.Patch.

### Decision

**A — Monotonic auto-increment integer per Passport, assigned inside the publication transaction.**

### Rationale

1. **Simplicity:** Version 1, 2, 3... is universally understood.
2. **Sorting:** Natural sort order for version history.
3. **URL cleanliness:** `/p/{id}/versions/3` is clean and short.
4. **Uniqueness guaranteed:** `UNIQUE(passport_id, version_number)` constraint.
5. **No gaps from failed publications:** Version number is assigned within the successful publication transaction. Failed publications do not consume version numbers.

### Assignment Algorithm

```sql
-- Inside publication transaction with row lock on product_passports:
INSERT INTO product_passport_versions (..., version_number, ...)
SELECT ..., COALESCE(MAX(version_number), 0) + 1, ...
FROM product_passport_versions
WHERE passport_id = ?
```

### Consequences

- Version numbers start at 1 and increment by 1.
- No gaps (failed publications roll back and do not consume numbers).
- Version number is immutable after publication.
- Cannot be reused after version rollback/delete.

### Rejected Alternatives

**Option B (Timestamp)** was rejected because:
- Timestamps are long and ugly in URLs.
- Sorting works but is less intuitive than integers.
- Two publications in the same second could collide.

**Option C (Semantic)** was rejected because:
- Overkill for passport versioning.
- No concept of "breaking change" in a regulatory document.
- MAJOR.MINOR implies a branching model that doesn't apply.

---

## R2-PUB-007: Public Identifier Format

### Context

Each passport needs a stable, non-enumerable public identifier for URLs and QR codes.

### Options Considered

- **A: UUIDv7.** Time-sortable UUID with embedded timestamp.
- **B: ULID.** Universally Unique Lexicographically Sortable Identifier.
- **C: Random URL-safe token.** Custom format, shorter.
- **D: Numeric ID.** Sequential.

### Decision

**A — UUIDv7 (time-sortable UUID).**

### Rationale

1. **R1 consistency:** R1 uses UUIDs for all route binding. UUIDv7 maintains the same hex format while adding time-based sorting for database index efficiency.
2. **Non-enumerable:** UUIDv7 contains 74 random bits (for 1ms precision), providing sufficient entropy to prevent practical enumeration. It is not sequentially guessable.
3. **URL-safe:** UUID hex format is URL-safe without encoding.
4. **Index-friendly:** Time-ordered prefix reduces B-tree index fragmentation compared to UUIDv4.
5. **Global uniqueness:** 74 random bits + 48-bit timestamp ensures no collisions across Companies or deployments.

### UUIDv7 Security Properties

| Property | Value |
|---|---|
| **Structure** | 48-bit Unix timestamp (ms) + 4-bit version + 12-bit rand_a + 2-bit variant + 62-bit rand_b |
| **Random bits** | 74 bits total (12 + 62) |
| **Timestamp precision** | Milliseconds |
| **Timestamp leakage** | UUIDv7 reveals the approximate creation time of the Passport (millisecond precision). This is acceptable because: (a) creation time is not sensitive metadata; (b) public_id is not an authorization credential; (c) publication timestamp is already public. |
| **Enumeration resistance** | 2^74 possible values per millisecond — not practically enumerable |
| **Not a secret** | `public_id` is a public identifier, not an authorization credential. It is visible in URLs, QR codes, and API responses. |
| **Authorization** | NEVER relies on `public_id` alone. All internal operations require authentication + authorization + tenant membership. Preview access requires explicit permission checks — `public_id` knowledge alone does not grant preview. |
| **Cross-tenant concealment** | Unknown/unpublished `public_id` always returns generic 404/410. Response does not reveal whether the passport exists or belongs to a different Company. |

### Format

```
public_id: 018f3a5c-9d2b-7e1a-4f3c-8b9d0e1f2a3b  (36-char hex with hyphens)
```

For public URLs: 32-char hex without hyphens (shorter QR codes):
```
/p/018f3a5c9d2b7e1a4f3c8b9d0e1f2a3b
```

### Consequences

- `UNIQUE` constraint on `public_id` column (global uniqueness).
- Generated at Passport creation (not publication).
- Stable across all versions of the same Passport.
- Used in public URLs, QR codes, API references (external identity only).
- Different from internal `uuid` (used for authenticated route binding).
- Internal resources (management API, preview) use `uuid` routing, NOT `public_id`.
- Case-insensitive comparison (stored lowercase).

### Rejected Alternatives

**Option B (ULID)** — Not available in R0; would require new dependency. UUIDv7 provides equivalent time-sorting benefits.

**Option C (Custom token)** — No standard library support; must implement collision detection. UUIDv7 is standard.

**Option D (Numeric ID)** — Information leakage, enumerable, violates R1 convention. Rejected outright.

---

## R2-PUB-008: Product Lifecycle Publication Dependency

### Context

R1 Products have a lifecycle (draft → active → archived). The Passport publication must define its dependency on Product status.

### Options Considered

- **A: Require active Product.** Only active Products can have passports published.
- **B: Allow draft Product passports.** Draft Products can have draft passports (for preparation). Publication requires active Product.
- **C: No dependency.** Passport lifecycle is completely independent of Product status.

### Decision

**B — Draft passports allowed for draft Products; publication requires active Product + Catalog readiness passed.**

### Rationale

1. **Workflow alignment:** Companies can prepare passport content in parallel with completing product data. This avoids a sequential bottleneck.
2. **Publication safety:** Publishing requires the Product to be `active` AND pass Catalog readiness. This ensures only properly configured Products have public passports.
3. **Graceful degradation:** If a Product is returned to draft after passport publication, the published passport remains available (see §Catalog Lifecycle Interaction in domain document).
4. **Editing safety:** Draft passport for draft Product: all fields editable. Draft passport for active Product: all fields editable. Published passport for archived Product: remains available (snapshot is immutable anyway).

### Publication Prerequisites

| Product Status | Can Create Draft | Can Publish | Published Passport Behavior |
|---|---|---|---|
| `draft` | Yes | **No** — Product must be active + readiness passed | N/A |
| `active` | Yes | **Yes** — if Catalog readiness passed | Publicly available |
| `archived` | No (must restore first) | No | Already published passports remain available per Company policy |

### Consequences

- Publication Action validates Product status as a hard blocker.
- Passport readiness check includes Catalog readiness as a prerequisite.
- Draft Product with draft Passport: both are editable independently.
- Archiving a Product does not remove its published passport history.
- Restoring a Product to active does not automatically republish its passport (new publication required).

### Rejected Alternatives

**Option A (Active only)** — Forces sequential workflow: complete Product → then start Passport. Slower time-to-publish.

**Option C (No dependency)** — Would allow publishing a passport for a Product with missing required data, violating the principle that the passport represents verified product information.

---

## R2-PUB-009: Media Pinning — Copy-on-Publish Immutable Assets

### Context

R1 ProductMedia is mutable — images can be replaced, deleted, or reordered. A published passport must guarantee that the bytes shown to public consumers are the exact bytes approved at publication time. Checksum-only pinning against mutable Catalog files is insufficient because:
- The underlying file on disk could be replaced between publication and delivery.
- Catalog media cleanup could remove the file.
- The file's immutability is not guaranteed by storage infrastructure.

### Options Considered

- **A: Immutable content-addressed asset object.** Files stored by SHA-256 hash. Same content = same storage. Reference counting for cleanup.
- **B: Immutable MediaVersion with retained bytes.** Catalog media gets versioning; published versions "pin" the media version. Cleanup excluded for pinned versions.
- **C: Copy-on-publish publication asset.** At publication time, media files are physically copied to an independent passport assets storage. The copy is the published asset.

### Decision

**C — Copy-on-publish. At publication time, every media file included in the passport is physically copied to a dedicated `passport_assets` storage disk. The copy is immutable and independent of Catalog storage.**

### Rationale

1. **Strongest immutability guarantee:** The published bytes are physically separate from mutable Catalog storage. No Catalog operation (media replacement, deletion, cleanup) can affect published assets.
2. **No R1 modifications required:** R1 media management remains unchanged. No versioning, reference counting, or pinning flags needed on `product_media`.
3. **Simple cleanup:** When a passport version is administratively purged, its copied assets are deleted. No reference counting across versions or across passports.
4. **Clear ownership boundary:** Catalog media belongs to the Catalog bounded context. Passport assets belong to the Publication bounded context. The copy explicitly transfers ownership.
5. **Acceptable storage cost (pilot):** Estimated worst case: 50 images × 500KB average × 1000 products × 5 versions = ~125GB. Pilot reality (1 Company, ~100 products): ~2.5GB. Negligible at pilot scale.

### Copy-on-Publish Contract

**Storage layout:**
```
passport_assets/{company_uuid}/passports/{passport_uuid}/versions/{version_number}/{media_uuid}.{ext}
```

**Publication-time procedure:**
1. For each media item selected for the passport, read the file from Catalog storage (`catalog_media` disk).
2. Compute SHA-256 checksum of the source file.
3. Copy the file to the passport assets storage path.
4. Compute SHA-256 checksum of the copied file — MUST match source checksum.
5. Store the pinned reference in the snapshot JSON.

**Pinned Asset Reference in Snapshot:**
```json
{
  "media_uuid": "01JN5QZ8K7X2W3Y4R5T6U7V8B",
  "filename": "product_front.jpg",
  "mime_type": "image/jpeg",
  "size_bytes": 102400,
  "width": 1200,
  "height": 800,
  "checksum_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
  "alt_text": {"sv": "Produkt framifrån", "en": "Product front view"},
  "is_primary": true,
  "sort_order": 10
}
```

Note: `storage_path` is NOT included in the public snapshot. It is stored in the `product_passport_assets` table for internal delivery.

### Asset Identity

| Property | Source | Immutable |
|---|---|---|
| `media_uuid` | R1 ProductMedia UUID at publication time | Yes (in snapshot) |
| `checksum_sha256` | Computed from copied bytes | Yes |
| `mime_type` | From R1 ProductMedia at publication time | Yes |
| `size_bytes` | From copied file | Yes |
| `width`, `height` | From R1 ProductMedia at publication time | Yes |
| `filename` | From R1 ProductMedia at publication time | Yes |
| Physical file path | `passport_assets/{company_uuid}/passports/{passport_uuid}/versions/{version_number}/{media_uuid}.{ext}` | Yes (never changes after copy) |

### Public Delivery Contract

1. Read the pinned reference from the current published version's snapshot JSON.
2. Locate the file in `passport_assets` storage using the `product_passport_assets` table.
3. Verify `sha256(file_content) === pinned_checksum`.
4. If match: serve with `Content-Type`, `Content-Length`, `ETag: "{checksum}"`, `Cache-Control: private, max-age=86400`.
5. If checksum mismatch: **CRITICAL** operational alert. Return 500. The published asset is corrupted.
6. If file missing: **ERROR** operational alert. Return 410.

### Immutability Guarantees

| Guarantee | Enforcement |
|---|---|
| Published bytes cannot be replaced | File copied to passport assets disk; no write path exists for published assets |
| Catalog deletion cannot remove published bytes | Physical separation — Catalog storage is independent |
| Catalog cleanup cannot remove published bytes | `catalog:media-cleanup` operates on `catalog_media` disk only; never touches `passport_assets` disk |
| New Catalog upload does not alter historical version | Copy is independent; no back-reference to Catalog media |
| Published asset belongs to same Company | `company_uuid` in storage path matches Passport Company |
| Physical purge is controlled and auditable | `passport:purge` CLI command with explicit `--execute` flag; audit event logged |

### What Happens When Catalog Media Changes

| Catalog Action | Effect on Published Passport |
|---|---|
| Replace media file | **No effect.** Passport has its own independent copy. |
| Delete media (soft-delete) | **No effect.** Passport copy is independent. |
| Change alt text/metadata | **No effect.** Snapshot captured metadata at publication time. |
| Hard-delete media + file cleanup | **No effect.** `catalog:media-cleanup` does not touch passport assets. |
| Product archived | **No effect.** Passport copy is independent. |
| Product hard-deleted | **No effect.** Passport copy is independent. |

### Cleanup and Retention

| Action | Behavior |
|---|---|
| Passport version purged (admin) | Corresponding passport assets directory deleted |
| Passport Company deleted | All passport assets for that Company deleted as part of purge |
| Orphan asset detection | `passport:asset-integrity-check` verifies all referenced assets exist and have correct checksums |
| Storage growth management | `passport:show-stats` reports storage usage per Company |

### Storage Costs (Pilot Estimate)

| Scenario | Assets | Avg Size | Total |
|---|---|---|---|
| 1 product, 5 images, 3 versions | 15 files | 500 KB | 7.5 MB |
| 100 products, 5 images, 3 versions | 1,500 files | 500 KB | 750 MB |
| 1000 products, 10 images, 5 versions | 50,000 files | 500 KB | 25 GB |

Pilot reality (~100 products): well under 1 GB. Acceptable.

### Consequences

- **R2.2 migration:** Add `passport_assets` filesystem disk configuration.
- **R2.6 publication:** Implement file copy + checksum verification during publish transaction.
- **R2.7 public delivery:** Read from `passport_assets` disk, never from `catalog_media`.
- **Operations:** `passport:asset-integrity-check` verifies all published assets.
- **Publish latency:** File copy adds ~50ms per 500KB image (local disk). Acceptable (< 2 seconds total for typical passport).

### Rejected Alternatives

**Option A (Content-addressed)** was rejected for R2 because:
- Requires reference counting infrastructure across all passport versions.
- Shared storage means one file corruption affects multiple passports.
- Cleanup requires knowing all references before deletion.
- More complex to implement correctly.

**Option B (MediaVersion with retention pins)** was rejected because:
- Requires modifying R1 `product_media` storage and cleanup logic.
- R1 media cleanup must become passport-aware.
- Violates bounded context separation (Catalog would need to know about Passports).
- R1 Media was not designed with versioning — retrofitting is invasive.

**Checksum-only pinning (original ADR-009)** was rejected as insufficient because:
- Does not guarantee physical byte immutability.
- A file on disk can be replaced while the checksum in the snapshot remains unchanged.
- Catalog cleanup could delete the file.
- R1 media management was designed for internal catalog use, not for public regulatory document delivery.

---

## R2-PUB-010: Document Version Pinning

### Context

Documents attached to passports (certificates, data sheets, declarations) may be updated by the Company. Published passports must pin specific document versions.

### Decision

**Documents have their own versioning. Passport versions pin specific document versions by checksum.**

### Document Model (Conceptual)

```
Document
  ├── uuid
  ├── company_id
  ├── filename
  ├── label (translatable)
  ├── type (certificate, datasheet, declaration, manual, other)
  ├── language
  ├── expiration_date (nullable)
  └── DocumentVersions
       ├── uuid
       ├── version_number
       ├── storage_path
       ├── mime_type
       ├── size_bytes
       ├── checksum_sha256
       ├── uploaded_at
       └── uploaded_by
```

### Pinning Contract in Passport Snapshot

```json
{
  "document_uuid": "01J... (Document UUID)",
  "document_version_uuid": "01J... (DocumentVersion UUID)",
  "filename": "safety_data_sheet.pdf",
  "mime_type": "application/pdf",
  "size_bytes": 245760,
  "checksum_sha256": "abc123...",
  "language": "sv",
  "label": {"sv": "Säkerhetsdatablad", "en": "Safety Data Sheet"},
  "type": "certificate",
  "expiration_date": "2027-12-31"
}
```

### Document Lifecycle

| Action | Effect on Published Passport |
|---|---|
| Upload new document version | **No effect.** Published passport pins the old version. |
| Delete document | **No effect.** Passport has the pinned version reference. Physical file retained. |
| Expire document | **No effect on published passport.** New passport draft shows expiration warning during readiness. |
| Replace document file | **No effect** if it's a new version (correct). If same version file is replaced, checksum mismatch at delivery time. |

### Consequences

- Documents are a separate module with their own versioning (R2.3).
- Publication pins specific document version UUID + checksum.
- Public document delivery validates checksum against pinned reference.
- Document management is decoupled from published passports.

---

## R2-PUB-011: Translation Publication Model

### Context

R2 pilot requires Swedish (default) + English. Future R2 stages will add more languages.

### Options Considered

- **A: One version per language.** Separate publication for each language. Different publish dates possible.
- **B: All languages in one version.** Single publication includes all enabled languages. Immutable together.

### Decision

**B — One Passport Version contains immutable payload for all published languages.**

### Rationale

1. **Regulatory coherence:** All language versions of the same DPP should represent the same verified state. Publishing them separately risks language divergence.
2. **Atomicity:** One publish operation = one version = all languages. No partial language publications.
3. **Consumer clarity:** A passport version represents "the approved information as of date X." All translations reflect that same approval point.
4. **Simplicity:** One version number covers all languages. No need to track "Swedish at version 3, English at version 2."

### Language Configuration

| Language | Code | Pilot Required | Fallback |
|---|---|---|---|
| Swedish | `sv` | Yes (default) | — |
| English | `en` | Yes | → `sv` |
| (Future) | — | Per Company config | → `sv` |

### Publication Readiness per Language

- Default language (`sv`): All required DPP sections must be filled.
- Additional required languages (`en`): All required DPP sections must be filled.
- A language is "ready" when all mandatory sections for that language have content.
- Publication is blocked if any enabled required language is not ready.

### Snapshot Language Structure

```
snapshot_payload:
  default_language: "sv"
  available_languages: ["sv", "en"]
  sections:
    product_identity:
      sv: { name: "...", ... }
      en: { name: "...", ... }
    dpp_sections:
      manufacturer:
        sv: { ... }
        en: { ... }
```

### Public Language Resolution

```
1. Explicit URL parameter:  /p/{id}?lang=en
2. Path-based:              /p/{id}/en  (future consideration)
3. Accept-Language header:  Highest-weight matching enabled language
4. Browser detection:       NOT used
5. Fallback:                Passport default language (sv)
```

### Missing Translation Behavior

- If a section has content in `sv` but not in `en`: the `en` section shows the Swedish content with indicator "(Swedish)" or falls back silently per Company preference.
- If the user selects `en` but some sections are missing: those sections render in `sv` as fallback.
- No error is shown to the consumer — the fallback is seamless.

### Consequences

- One version = one immutable set of all language payloads.
- Cannot publish Swedish-only and add English later without a new version.
- Adding a new language to a passport requires a new version (or the language is added during draft).
- Snapshot JSON includes all language content in one payload.

### Rejected Alternatives

**Option A (Per-language versions)** was rejected because:
- Language versions would diverge in content.
- Complex: "which version is current for language X?"
- QR codes would need language-specific logic.
- Violates DPP principle: same product information, different language.

---

## R2-PUB-012: Synchronous Transaction vs Asynchronous Publication

### Context

Publication involves multiple steps: validation, snapshot building, checksum calculation, database writes, cache invalidation, and side effects (QR generation, analytics setup). Some steps could be async.

### Options Considered

- **A: Synchronous transaction.** Everything in one database transaction. User waits for completion.
- **B: Async via queue.** User submits; job publishes later. User polls or is notified.
- **C: Hybrid.** Core publication in sync transaction; side effects async after commit.

### Decision

**C — Hybrid: Core publication in synchronous transaction; side effects async after commit.**

### Rationale

1. **Atomicity:** The critical path (validation → snapshot build → persist → mark published → switch pointer) must be all-or-nothing. A database transaction guarantees this.
2. **User feedback:** The publisher sees immediate success or failure. No polling or webhooks needed for pilot.
3. **Performance:** Publication is not expected to be slow (< 2 seconds for typical passport). Acceptable for synchronous response.
4. **Resilience:** If QR generation or cache invalidation fails, the passport is already published. These side effects can retry independently without blocking publication.

### Transaction Boundary

**Inside transaction (synchronous):**
1. Authorize user
2. Lock Passport row
3. Validate Product active + Catalog readiness
4. Validate Passport readiness
5. Resolve translations
6. Resolve pinned assets and documents
7. Build canonical JSON snapshot
8. Validate snapshot schema
9. Calculate snapshot SHA-256 checksum
10. Assign next version number
11. INSERT immutable version row
12. UPDATE previous published version → superseded
13. UPDATE Passport `current_version_id` pointer
14. UPDATE Passport status → published (if first publication)
15. INSERT audit event
16. COMMIT

**After commit (async, via queue/dispatcher):**
17. Dispatch `ProductPassportPublished` domain event
18. Invalidate public page cache
19. Generate/regenerate QR code image
20. Initialize analytics counters
21. Update search index (future)

**Before transaction (validation-only):**
- Authorize user (full authorization check)
- Resolve Company context
- Validate Passport exists and belongs to Company
- Validate Product exists and belongs to Company

### Failure Handling

| Failure Point | Behavior |
|---|---|
| Validation fails (before transaction) | Return error response. Nothing written. |
| Transaction fails (any step 1-16) | Full rollback. No version created. No audit event persisted. |
| After-commit event fails (step 17-21) | Passport already published. Event retried. QR/analytics catch up. |
| Queue is down | Passport published but QR not generated. Admin UI shows "QR pending." Retry on queue recovery. |

### Consequences

- Publication is a synchronous operation from the user's perspective.
- Transaction duration: targeted < 2 seconds for typical passports.
- After-commit events use Laravel's `dispatchAfterResponse()` or queued event listeners.
- QR generation is a cacheable side effect, not a publication blocker.

### Rejected Alternatives

**Option A (Fully synchronous)** — QR generation and cache warming could slow down the publication response unnecessarily.

**Option B (Fully async)** — User has no immediate confirmation. Adds UI complexity (polling, notifications). Overkill for pilot.

---

## R2-PUB-013: Publish Retry and Idempotency Behavior

### Context

Network failures, double-clicks, or client-side retries could result in duplicate publication attempts for the same draft.

### Options Considered

- **A: Idempotent by draft revision.** Same draft content → same published version returned (idempotent).
- **B: Conflict on duplicate.** Second publish attempt returns 409 Conflict.
- **C: Allow duplicates.** Each publish creates a new version (not recommended).

### Decision

**B — Return controlled conflict (409) on duplicate publication attempt of the same draft.**

### Rationale

1. **Safety:** Creating two identical published versions from the same draft is almost certainly unintentional.
2. **Version number waste:** Even with idempotent return (Option A), the semantic is confusing — "I clicked publish twice and got the same version."
3. **Explicit action:** If the user wants a new version with the same content, they explicitly create a new draft and publish it.
4. **Detection:** The draft version row stores a `content_revision` (incremented on each draft update). The published version stores the `draft_revision` at publication time. Duplicate detection: if the draft's current `content_revision` equals an already-published version's `draft_revision`, reject.

### Detection Algorithm

```sql
-- Check if this draft revision was already published:
SELECT 1 FROM product_passport_versions
WHERE passport_id = ?
  AND draft_revision = ?
  AND status = 'published'
LIMIT 1
```

If found → 409 Conflict: "This draft has already been published. Create a new draft to publish changes."

### Idempotent Retry for Same Request

If the exact same HTTP request is retried (same `X-Idempotency-Key` header), return the existing published version (200 OK). This handles network-level retries where the first request succeeded but the response was lost.

**Note:** Full `Idempotency-Key` infrastructure is deferred to a future platform stage (not R2). For R2 pilot, the draft revision check provides sufficient protection against double-publish.

### Double-Click Protection

The UI must disable the "Publish" button after first click and show a loading state until the response is received.

### Consequences

- Draft row has `content_revision` counter (integer, incremented on each save).
- Published version row stores `draft_revision` (the revision at publication time).
- Duplicate detection: same draft revision cannot be published twice.
- Client-side button debouncing is the first line of defense.

### Rejected Alternatives

**Option A (Idempotent)** was considered but rejected because:
- Confusing UX: user clicks "Publish," gets a success response, but nothing was actually published (already done).
- Better to tell the user explicitly that the draft was already published.

**Option C (Duplicates allowed)** was rejected because:
- Creates meaningless identical versions.
- Wastes version numbers.
- Confuses version history.

---

## R2-PUB-014: Unpublish and Archive Semantics

### Context

A Company may need to remove a passport from public access (unpublish) or retire it permanently (archive).

### Decision

### Unpublish

**Unpublish removes the current published pointer. The public URL returns 410 Gone. All versions are preserved. The passport can be republished.**

| Aspect | Behavior |
|---|---|
| Public URL `/p/{id}` | Returns 410 Gone with message |
| Version history | Preserved (all versions remain) |
| Current version pointer | Set to NULL |
| Passport status | Set to `unpublished` |
| Audit event | `passport.unpublished` |
| Republish capability | Yes (restores `current_version_id` to most recent published version) |
| Cache | Public page cache invalidated |

### Archive

**Archive retires the passport permanently. Not publicly accessible. All data preserved. Can be restored (returns to draft).**

| Aspect | Behavior |
|---|---|
| Public URL `/p/{id}` | Returns 410 Gone |
| Passport status | Set to `archived` |
| Draft | Discarded (if exists) |
| All versions | Preserved |
| Audit event | `passport.archived` |
| Restore capability | Yes (sets status to `draft`; requires re-publish) |
| Document cleanup | None (documents retained per retention policy) |

### Restore

**Restore returns an archived passport to draft status.**

| Aspect | Behavior |
|---|---|
| Previous status | `archived` → `draft` |
| Existing draft | None (was discarded on archive) |
| Public URL | Remains 410 (until republished) |
| Audit event | `passport.restored` |

### Republish

**Republish restores the current published pointer to the most recent published version.**

| Aspect | Behavior |
|---|---|
| Previous status | Must be `unpublished` |
| Current version pointer | Restored to most recent published version |
| Public URL | Returns 200 with the most recent version |
| No new version created | Republish is a pointer change, not a new publication |
| Audit event | `passport.republished` |

### Forbidden Transitions

- `unpublished → published` (must go through republish, which only works if there's a previous published version)
- `archived → published` (must go through restore → publish)
- `draft → unpublished` (meaningless — draft has no public URL)
- `published → draft` (must unpublish first)
- Physical deletion (not available through UI)

### Consequences

- Unpublish/republish is a lightweight pointer toggle. Does not touch versions.
- Archive is a lifecycle status change. Preserves everything.
- Restore is a lifecycle status change. Does not touch versions.
- No data is physically deleted by any user-facing operation.

---

## R2-PUB-015: Historical Version Public Access

### Context

Previous published versions may be of interest to regulators, auditors, or consumers tracking product changes over time.

### Options Considered

- **A: No public access.** Only the current version is public. History is internal-only.
- **B: All versions public.** Every published version is accessible at a version-specific URL.
- **C: Configurable per Company.** Company can choose to make historical versions public.

### Decision

**A — Historical versions are NOT publicly accessible for the R2 pilot. Version-specific URLs exist internally for Company users only. Public access is restricted to the current published version.**

### Rationale

1. **Pilot simplicity:** Most consumers only need the current version. Historical versions add complexity without pilot value.
2. **Regulatory caution:** EU DPP regulations (as understood at time of writing) require access to the current valid passport, not to historical versions. Making all versions public could confuse consumers about which version is current.
3. **Company control:** Companies may not want every historical edit publicly visible. Some historical versions may contain errors that were corrected in subsequent versions.
4. **Future readiness:** The version-specific URL infrastructure is implemented internally (for Company users). Enabling public access is a configuration change, not an architecture change.

### Pilot Default (R2)

| Aspect | Pilot Behavior |
|---|---|
| **Current version public access** | Yes — `/p/{public_id}` resolves to current published version |
| **Historical versions public access** | **No** — unauthenticated requests to `/p/{public_id}/versions/{n}` return **404 Not Found** |
| **Historical versions internal access** | Yes — authenticated Company users can view all versions via management UI |
| **Superseded versions** | Not publicly accessible; visible in Company version history |
| **Withdrawn versions** | Not publicly accessible; marked in Company version history |
| **Canonical URL** | `/p/{public_id}` is the only public-facing URL |
| **Indexing** | Current version: `X-Robots-Tag: noindex, nofollow` (pilot default). Historical versions: not accessible to crawlers. |
| **Legally withdrawn documents** | If a document within a historical version is legally withdrawn, the entire version may be marked as `withdrawn` (rare administrative action) |

### HTTP Behavior by Version State

| Version State | Public URL `/p/{id}/versions/{n}` | Authenticated Company User |
|---|---|---|
| `published` (current) | N/A — served via `/p/{public_id}` | Viewable in management UI |
| `published` (superseded) | **404 Not Found** | Viewable in version history |
| `withdrawn` | **410 Gone** (if the version itself is withdrawn) | Viewable with "WITHDRAWN" indicator |
| `draft` | **404 Not Found** | Viewable in preview |

### R3 Extension

In R3, a Company-level configuration option (`passport.settings.historical_versions_public`) can be added to optionally make superseded versions publicly accessible. This is an additive change — the infrastructure (version-specific URLs, authorization checks) already exists.

### Consequences

- Version-specific URL routes exist for authenticated Company users only.
- Public `404` response for unauthenticated access to historical versions — generic, does not reveal whether version exists.
- `withdrawn` is a rare administrative status, not part of normal workflow.
- Company version history UI shows all versions with status indicators.
- No public indexability of historical versions (pilot default).

### Rejected Alternatives

**Option B (All versions public)** was rejected because:
- Consumers could be confused about which version is current.
- Companies may not want all historical edits publicly visible.
- No pilot requirement for public version history access.

**Option C (Configurable) as R2 MVP default** was rejected because:
- Adds configuration complexity before pilot need is established.
- Infrastructure for both paths must be built and tested in R2.
- R3 can add the configuration option when real demand exists.

---

## R2-PUB-016: Cache Invalidation Model

### Context

The public passport page is read-heavy. Caching is essential for performance. Cache must be invalidated when a new version is published, when the passport is unpublished, or when archived.

### Decision

**Cache key per passport public_id + current version ID. Invalidation on publish, unpublish, archive, republish.**

### Cache Key Design

```
passport:public:{public_id}:v{current_version_id}:{language}
```

### Cache Invalidation Events

| Event | Action |
|---|---|
| **Publish new version** | New cache keys created (new version ID). Old keys expire naturally (TTL) or are actively invalidated. |
| **Unpublish** | All public cache keys for the passport are invalidated. |
| **Republish** | Cache keys for the current version are warmed (optional). |
| **Archive** | All public cache keys for the passport are invalidated. |
| **Company branding change** | Not relevant — branding is in the snapshot, not live. Only affects new publications. |
| **Document expiration** | No cache invalidation. Expiration metadata is in the snapshot. Public page renders the expiration date. |

### Cache TTL

| Cache Type | TTL | Rationale |
|---|---|---|
| Public page HTML | 1 hour | Balance freshness vs performance. Version change invalidates early. |
| Public media (images) | 24 hours | Media is pinned by checksum — immutable. |
| Public documents | 24 hours | Documents are pinned by checksum — immutable. |
| Preview | No cache (`no-store`) | Preview is per-user, short-lived. |

### ETag Support

Public page responses include:
- `ETag: "{snapshot_checksum}"` — changes only when a new version is published.
- `Cache-Control: public, max-age=3600`
- `Last-Modified: {published_at}`

This enables CDN caching and conditional requests.

### Cache Warming (Post-Publication)

After successful publication, the after-commit handler:
1. Dispatches a cache-warming job (low priority).
2. The job renders and caches the public page for all enabled languages.
3. Failure to warm cache does not affect the publication — the first consumer request will populate the cache naturally (cache miss → render → cache).

### Consequences

- Cache is per-Company via the public_id (which is globally unique, inherently tenant-isolated).
- No risk of cross-Company cache pollution.
- Preview pages have `Cache-Control: no-store` — never cached.
- CDN configuration aligns with these cache TTLs.

---

## R2-PUB-017: Retention and Purge Boundary

### Context

How long are published passport versions retained? Can a Company physically delete passport data?

### Decision

**Default retention: all published versions retained indefinitely. Unpublish removes public access. Archive preserves all data. Physical purge is a controlled administrative procedure (not available in UI).**

### Retention Policy

| Data | Retention | Notes |
|---|---|---|
| Published versions | Indefinite | Immutable records of what was published |
| Draft versions | Until published or discarded | Discarded drafts are deletable after 90 days |
| Documents (pinned) | As long as the referencing version exists | Physical files retained |
| Media (pinned) | As long as the referencing version exists | Physical files retained |
| Audit events | Per R0 audit retention (365 days default) | Extendable |
| Analytics data | 24 months (rolling) | Anonymized counters only |

### Company Deletion

When a Company is deleted (administrative operation):
1. All passports owned by the Company are archived.
2. Public URLs return 410.
3. Passport data preserved per retention policy.
4. Physical deletion of passport data requires a separate administrative procedure.

### Product Deletion

When a Product is hard-deleted:
1. Associated passport references product UUID that no longer resolves.
2. Published passport versions remain (immutable snapshots).
3. Product identity data is in the snapshot — can still be displayed.
4. Product soft-delete (catalog archive) does not affect passports.

### Physical Purge Procedure (Administrative Only)

A CLI command `passport:purge` (not available in UI) can physically remove:
- Passport data for a specific Company (all passports, all versions).
- Individual archived passports (with explicit `--passport` and `--force` flags).
- Orphaned pinned files (documents/media no longer referenced by any published version).

This is NOT part of the normal user workflow. It exists for GDPR data deletion requests and storage management.

### Consequences

- Normal user operations (unpublish, archive) never physically delete data.
- "Delete" in the UI means "archive" (remove from active use).
- Physical deletion is a controlled CLI operation with confirmation.
- Retention periods are configurable per Company in future stages.
- Regulatory minimum retention periods (EU DPP: to be determined) can be configured when known.

---

## R2-PUB-018: Readiness Separation

### Context

Before publication, the system must verify that the passport is complete. This verification has multiple layers: Catalog readiness, Passport content readiness, and technical publication validation.

### Decision

**Three-layer readiness model: Catalog Readiness → Passport Readiness → Publication Validation.**

### Layer 1: Catalog Readiness

**Checked by:** R1 `ProductActivationReadinessService`

Validates that the underlying Product meets R1 publication standards:
- Name, slug, primary category
- Default Variant with SKU
- Required attributes
- Primary media (soft gate)

If Catalog readiness fails → passport publication blocked. The passport cannot be published for a product that is not catalog-ready.

### Layer 2: Passport Readiness

**Checked by:** `PassportReadinessService` (new R2 service)

Validates that the passport content is complete:
- All mandatory DPP sections have content in required languages
- Required documents are attached
- Public identifiers are configured
- Translations are complete for required languages
- No expired documents (warning only)
- Company public profile is configured

**Blocker categories:**
| Blocker | Description |
|---|---|
| `missing_public_identity` | No public identifier configured |
| `missing_manufacturer_info` | Manufacturer section empty in default language |
| `missing_safety_info` | Safety/compliance section empty in default language |
| `missing_recycling_info` | Recycling/disposal section empty in default language |
| `missing_default_language` | Required sections empty in default language |
| `missing_required_language` | Required sections empty in an enabled language |
| `missing_mandatory_documents` | Required document types not attached |
| `expired_mandatory_documents` | Required documents have expired |
| `product_not_active` | Product is not active |
| `product_not_ready` | Product fails Catalog readiness |

**Warning categories:**
| Warning | Description |
|---|---|
| `optional_sections_empty` | Non-mandatory DPP sections empty |
| `missing_recommended_documents` | Recommended but not required documents missing |
| `document_expiring_soon` | Document expires within 30 days |
| `missing_optional_languages` | Enabled but not required languages incomplete |
| `no_primary_media` | No primary product image set |
| `no_company_logo` | Company public profile has no logo |

### Layer 3: Publication Validation

**Checked by:** The Publish Action itself (in transaction)

Technical validation that a snapshot can be created:
- All pinned assets are accessible on disk
- All pinned assets have valid checksums
- Snapshot JSON is valid and within size limits
- Snapshot schema version is compatible
- No version number collision
- No concurrent publication in progress (lock held)

### Relationship

```
Publication
    ├── requires Catalog Readiness PASS
    ├── requires Passport Readiness PASS
    └── performs Publication Validation in transaction
```

Each layer is independently testable. Layer 1 is R1 code. Layers 2 and 3 are R2 code.

### Consequences

- Catalog readiness is checked by R1 service — not duplicated.
- Passport readiness is a new R2 service with passport-specific rules.
- Publication validation runs inside the publish transaction for safety.
- Readiness can be checked independently (GET endpoint) without attempting publication.
- Blocker codes are stable strings for API and UI consumption.

---

## References

- **R2 Pilot Scope:** [R2_PILOT_SCOPE.md](R2_PILOT_SCOPE.md)
- **R2 Publication Domain:** [R2_PUBLICATION_DOMAIN.md](R2_PUBLICATION_DOMAIN.md)
- **R1 Catalog Domain:** [../catalog/CATALOG_DOMAIN.md](../catalog/CATALOG_DOMAIN.md)
- **R1 Catalog Decisions:** [../catalog/CATALOG_DECISIONS.md](../catalog/CATALOG_DECISIONS.md)
