# ADR-R3-006 — Fortnox Source-of-Truth and Conflict Policy

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None (new integration)

---

## Context

NordiPass must integrate with Fortnox as the primary ERP for Swedish SME customers. Fortnox provides article data, pricing, and stock information. NordiPass extends this with DPP, documents, readiness, and publication.

## Decision

### Source-of-Truth Matrix

| Field | Fortnox Authoritative | NordiPass Authoritative | Manual Override | Sync Direction |
|-------|----------------------|------------------------|-----------------|----------------|
| Article number | Yes | — | No | Fortnox → NordiPass |
| Article name | Yes | Yes (DPP override) | Yes | Bidirectional with policy |
| Description | Yes | Yes (DPP override) | Yes | Bidirectional with policy |
| Price | Yes | — | No | Fortnox → NordiPass |
| Stock | Yes | — | No | Fortnox → NordiPass |
| GTIN/EAN | Yes | Yes | Override possible | Fortnox → NordiPass |
| Manufacturer | — | Yes | — | NordiPass only |
| Brand | — | Yes | — | NordiPass only |
| DPP data | — | Yes | — | NordiPass only |
| Documents | — | Yes | — | NordiPass only |
| Readiness | — | Yes | — | NordiPass only |
| Publication | — | Yes | — | NordiPass only |

### Conflict Policy
- When Fortnox data changes, NordiPass updates mapped fields unless a manual override exists
- Manual overrides are tracked per-field with timestamps
- Conflict is raised (visible in UI, logged in audit) when both sides have changes
- Resolution: user chooses Fortnox value or keeps NordiPass value
- Default: Fortnox wins for commercial data (price, stock); NordiPass wins for DPP data

### OAuth Token Storage
- Encrypted at rest using Laravel's encryption
- Stored in `company_settings` JSON column (initially)
- If scaling requires, a dedicated `integrations` table with `fortnox_tokens`

### External Identity Mapping
- Fortnox article number → NordiPass product variant SKU or custom mapping table
- Mapping table: `fortnox_article_mappings` (fortnox_article_id, company_id, product_id, variant_id)
- One Fortnox article can map to one NordiPass product variant

### Synchronization
- Scheduled (configurable interval, default hourly)
- Incremental: uses Fortnox "modified after" filter
- Webhook: Fortnox webhook for real-time updates (preferred when available)
- Polling fallback when webhook is unavailable

### Publication Policy
- Fortnox sync does NOT automatically publish passports
- Publication requires explicit approval workflow (R3.10)
- Synced data updates are reflected in passport drafts only

### Idempotency
- Sync operations use idempotency keys based on Fortnox article ID + modification timestamp
- Duplicate sync events for the same article state produce no duplicate changes

## Alternatives Considered

1. **Fortnox as full system of record**: Rejected — DPP data has no equivalent in Fortnox. Forcing Fortnox to hold DPP data would require custom fields and defeat the purpose of NordiPass.
2. **NordiPass as full system of record for all fields**: Rejected — pricing and stock data should come from the accounting system, not be manually maintained in NordiPass.

## Consequences

- Fortnox integration cannot begin until R3.5 (taxonomy) establishes product identity mapping
- Manual override tracking requires new database columns or a dedicated table
- Conflict resolution UI needed in product management
- Sync audit trail for compliance
