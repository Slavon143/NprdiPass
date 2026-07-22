# ADR-R3-003 — DPP Section Ownership and Inheritance

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None (extends R2 DPP model)

---

## Context

R2 DPP has 11 sections with flat field definitions in `DppSchemaRegistry`. R3 adds advanced sections (materials, environmental, repair, etc.) and introduces the concept of variant-level overrides and category-level defaults for certain DPP fields.

## Decision

### Section Ownership Model

| Section | Owner | Variant Override | Category Default |
|---------|-------|-----------------|------------------|
| Identity | Product | Yes (name override) | No |
| Manufacturer & Operator | Company/Product | No | No |
| Origin & Traceability | Product | No | No |
| Materials & Composition | Product | Yes (per-variant material list) | Yes (default materials per category) |
| Safety | Product | No | No |
| Usage & Care | Product | No | Yes (category template) |
| Repair & Spare Parts | Product | No | Yes (category template) |
| Recycling & Disposal | Product | No | Yes (category template) |
| Environmental Information | Product | No | No |
| Certifications & Documents | Product (via document association) | No | No |
| Support & Contact | Company/Product | No | Yes (company default) |

### Inheritance Rules
1. **Category defaults**: When a product is assigned to a category with DPP templates, those template values populate the passport draft as initial values.
2. **Variant overrides**: Certain fields (product name, materials composition) can be overridden per variant in the passport payload.
3. **Product overrides**: Product-level passport values override category defaults.
4. **Published snapshot**: Always contains the fully resolved data — no pointers to category templates or variant overrides.

### Schema Versioning
- DPP schema versioned (e.g., `1.0` for R2, `2.0` for R3)
- Schema version in passport versions table (`schema_version` column)
- `DppSchemaRegistry` returns schema for requested version
- Old published snapshots retain their original schema version

### Published Serialization
- Published passport payload contains fully resolved, denormalized data
- No dynamic resolution of category defaults at read time
- Immutable at publication time

## Alternatives Considered

1. **Live inheritance (resolve at render time)**: Rejected — public passport must be fast and immutable. Dynamic resolution adds latency, cache complexity, and makes historical reproducibility impossible.
2. **No category defaults**: Rejected — would require manual entry for every product, defeating the purpose of taxonomy governance (R3.5).

## Consequences

- DPP editor must show source of each value (category default, product value, variant override)
- Category template changes do NOT retroactively affect published passports
- New schema version = new `DppSchemaRegistry` entries
- Published snapshots are fully self-contained
