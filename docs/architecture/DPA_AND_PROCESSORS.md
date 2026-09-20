# DPA Summary and Processor Inventory (R3.16)

Status: R3.16 documentation deliverable (Privacy Gate). This document summarizes NordiPass's data processing posture and lists the sub-processors used by the R3 product baseline. It is a product-internal summary, not a signed customer DPA template; a customer-facing DPA must be issued through the legal channel before Public Beta contracts.

## Roles and Categories of Data

| Role | Data |
|------|------|
| Controller | NordiPass processes personal data on behalf of customer companies (tenants) and their end users |
| Personal data processed | Account name, e-mail address, login timestamps, membership/role records, invitations (recipient e-mail), notifications, API token metadata, audit trail entries referencing actor identity |
| Business (non-personal) data | Product catalogues, DPP content, published passport snapshots, import/export files, analytics aggregates (no raw IP, no full user-agent strings) |

## Processing Principles

1. **Minimization** — public analytics store no raw IP addresses, no full user-agent strings, and no full referrer URLs; public passport pages set no tracking cookies.
2. **Purpose limitation** — personal data is used only for account operation, tenant membership, security auditing, and legally required record keeping.
3. **Erasure** — users can export (`POST /profile/export`) and erase their data (account deletion). Erasure anonymizes personal data immediately and hard-deletes rows after `PRIVACY_USER_ERASURE_RETENTION_DAYS` via `privacy:purge`.
4. **Retention** — tenant audit logs: `AUDIT_RETENTION_DAYS` (365 default) via `nordipass:prune-audit-logs`; analytics: `config/analytics.php` retention + `analytics:prune`; webhook deliveries: `PRIVACY_WEBHOOK_DELIVERY_RETENTION_DAYS` via `privacy:purge`; platform audit (`log_name = platform`, including GDPR export/erasure events) is retained as an immutable security record.
5. **Security** — encryption at rest for OAuth tokens and webhook secrets, HMAC verification for webhooks and Stripe, rate limiting, tenant isolation enforced on every query, immutable audit trail.

## Processor Inventory

| Processor | Purpose | Data categories | Region |
|-----------|---------|-----------------|--------|
| MySQL 8 (managed hosting) | Primary application database | All categories above | EU (per deployment) |
| Object/file storage (deployment-managed) | Catalog media, document versions, backups | Files uploaded by tenants | EU (per deployment) |
| Stripe | Subscription billing, checkout, customer portal | E-mail, subscription metadata (payment data never stored by NordiPass) | Stripe EU/US per account configuration |
| Fortnox | ERP integration (tenant-initiated OAuth) | Article data, OAuth tokens (encrypted at rest in NordiPass) | Fortnox EU |
| Mail provider (SMTP) | Transactional e-mail | Recipient e-mail, message content | EU (per deployment) |
| CI/CD (GitHub Actions) | Build, test, audit pipelines | Source code only | US |
| Dependency/audit tooling (Packagist, npm registry) | Dependency resolution | Source metadata only | US |

## Sub-processor Change Control

Adding or replacing a sub-processor requires updating this inventory in the same change that introduces the integration, plus re-running the Privacy Gate checklist in `docs/architecture/R3_SECURITY_AND_PRIVACY.md`.
