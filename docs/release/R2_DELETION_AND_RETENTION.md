# R2 deletion and retention policy

## Principles

1. Company ownership is resolved before any mutation.
2. A bulk request is all-or-nothing: every requested UUID must resolve inside the current company before the first write.
3. Archived dependants still count. Deletion must not make a later restore invalid.
4. Published passport versions, validation evidence, and publication assets are retention records, not mutable catalog projections.
5. User-facing failures do not disclose whether a foreign-company UUID exists.

## Matrix

| Entity | Dependency | Policy | Action on delete/archive | Restore consequence | Audit/test evidence |
|---|---|---|---|---|---|
| Category | Child categories | Parent deletion follows the category hierarchy rules; an unsafe subtree is rejected. | No partial subtree mutation. | Hierarchy remains valid. | Category action/Feature tests |
| Category | Product-category pivot for active or archived product | Hard delete blocked while any product depends on it. | Return validation/domain failure. | Archived product can restore with its category intact. | `CategoryDeleteTest` |
| Category bulk selection | Own + foreign/unknown UUID | Reject the entire set. | No owned category is deleted/archived. | No cross-tenant partial state. | Bulk category tests |
| Attribute definition | Options | Options are part of the definition aggregate. | Aggregate removal allowed only when no product/variant values exist. | No dangling option/value relationship. | Attribute tests |
| Attribute definition | Product attribute values, including archived product owners | Hard delete blocked. | Values are not hard-deleted as a side effect. | Restored product retains valid attribute data. | `AttributeDeleteTest` |
| Attribute definition | Variant attribute values, including archived variant/product owners | Hard delete blocked. | Definition/value rows retained. | Restored variants remain valid. | `AttributeDeleteTest` |
| Attribute bulk selection | Own + foreign/unknown UUID | Reject entire set before mutation. | No partial delete/archive. | Tenant isolation maintained. | Bulk attribute tests |
| Product | Variants, attributes, media, documents, passport | Normal lifecycle is archive, not destructive removal of published evidence. | Archive records lifecycle state; dependent mutable catalog behavior follows aggregate rules. | Restore reactivates a structurally intact aggregate. | Lifecycle suites |
| Product bulk archive/status | Mixed valid/foreign/unknown UUIDs | Validate full company-scoped set first. | Generic failure, zero writes. | No partially archived batch. | `ProductBulkArchiveTest`, bulk status tests |
| Variant | Default-variant pointer | Default pointer must remain valid. | Delete/archive rules protect product invariant. | Product retains or establishes valid default variant. | Variant/lifecycle suites |
| Media | Published passport asset | Source media can change after publication; published copy cannot. | Catalog mutation does not rewrite publication asset. | Historical public version remains renderable. | Public immutable asset tests |
| Product document | Immutable document versions | Version rows are immutable; lifecycle uses archive/restore. | Current document lifecycle may change without rewriting a published copy/reference. | Restored document retains version history. | Document contract/pinning tests |
| Passport draft | Published passport version | Draft is mutable but optimistic-revision protected. | Archive/unpublish changes state; published history retained. | A later draft/version can be created without changing prior public data. | Publication suites |
| Published passport version | QR/public URL, media, documents, readiness evidence | Append-only retention record. | Never hard-delete through ordinary R2 UI flows. | Stable historical version and auditability. | Immutability/snapshot tests |
| Validation run/result | Publication/draft evaluation | Append-only evidence. | MySQL triggers reject update/delete. | Historical decision remains reproducible. | Validation evidence tests |
| Public QR identity | Passport `public_id` | Stable across versions. | Unpublish may make content unavailable, but identity is not regenerated on republish. | Existing QR resolves the current published version. | QR/publication tests + E2E |

## Atomic bulk algorithm

For category, attribute, product archive, and product status operations:

1. normalize and de-duplicate requested UUIDs;
2. query only rows belonging to the current company;
3. compare fetched count with requested count;
4. if counts differ, return a generic unavailable/validation failure;
5. only then enter the mutation loop/transaction.

This ordering prevents a valid owned row from changing when the same request contains a foreign or unknown UUID.

## Retention and operations

- Audit logs, failed jobs, backups, and scheduler heartbeat follow infrastructure retention configuration and scheduled pruning commands.
- Backup pruning is policy-driven and was verified in dry-run mode; acceptance did not destructively prune existing operator backups.
- Acceptance-created demo company data was removed using `nordipass:demo:reset`; the final testing database was then recreated with `migrate:fresh`.
- Acceptance server logs, temporary query scripts, and local dependency caches were removed. Final JUnit and browser report files were intentionally retained as release evidence.

## Verification result

Deletion and retention behavior has no known R2 release-blocking integrity or tenant-isolation gap. The final MySQL suite, focused delete/bulk tests, publication immutability tests, and manual browser lifecycle all passed.
