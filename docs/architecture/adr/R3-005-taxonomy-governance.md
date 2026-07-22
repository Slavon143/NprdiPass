# ADR-R3-005 — Taxonomy and Attribute Governance

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None (new capability)

---

## Context

R2 categories and attributes have no governance: any company can create any attribute, no attributes are required for a category, and there is no deprecation or controlled vocabulary lifecycle. R3 introduces taxonomy governance to support industry-specific DPP requirements.

## Decision

### Category Templates
- A category can define a "template" specifying:
  - Required attributes (attribute definitions that must have values)
  - Recommended attributes
  - Default DPP section values (see ADR-R3-003)
  - Assigned readiness profile

### Controlled Vocabularies
- Attribute definitions of type `select`/`multiselect` can be marked as `vocabulary_controlled`
- `vocabulary_controlled` options cannot be created/edited by non-admin users
- New options require approval

### Attribute Lifecycle
- Status: `active`, `deprecated`, `archived`
- `deprecated`: still usable on existing products, not assignable to new products, shows warning
- `archived`: not usable, read-only on existing products
- Deprecation replaces must be specified: `replaced_by_attribute_definition_id`

### Option Lifecycle
- Status: `active`, `deprecated`, `archived`
- `deprecated`: shows in existing values but not selectable for new values
- Options cannot be deleted if referenced by any product/variant attribute value

### Schema Assignment
- Categories can be assigned a readiness profile (see ADR-R3-002)
- Products inherit the profile from their primary category
- Product-level override of profile assignment (for edge cases)

### Product Migration
- When a category's required attributes change, existing products show "needs review" status
- No automatic value assignment
- Draft passport invalidation when category template changes relevant DPP sections
- Published passports remain unchanged

## Alternatives Considered

1. **Automatic product migration on template change**: Rejected — automatic data changes without user review are dangerous. Products should show warnings and require manual review.
2. **Block attribute deletion if any product references it**: Accepted — aligns with R2 soft-delete/archive pattern and database FK protection.
3. **Full semantic versioning for category templates**: Deferred to R4 — adds complexity that is not needed for Public Beta.

## Consequences

- New tables: `category_templates`, `category_template_attributes`, `category_template_dpp_defaults`
- New columns: `attribute_definitions.replaced_by_id`, `attribute_definitions.vocabulary_controlled`
- New integrity checks for template consistency
- Product list gains "needs review" status filter
