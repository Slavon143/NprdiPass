# R3 Architecture Baseline

## Based on R2 Accepted Implementation

### R2 Acceptance Commit: `c9f0794cabe30427f6643831c96bda7ac7411599` — Branch: `master`

---

## 1. Technology Stack

| Component | Version |
|-----------|---------|
| PHP requirement | `^8.4` (`composer.json`) |
| PHP observed in R3.1 workspace | 8.5.2 |
| Laravel | 13.19.0 |
| MySQL policy | 8.x only; `.env.testing` uses `DB_CONNECTION=mysql` |
| MySQL CLI observed in R3.1 workspace | Not on PATH |
| Node | 24.15.0 |
| npm observed in R3.1 workspace | Broken Windows shim; `npm-cli.js` missing |
| Composer | 2.6.6 |

---

## 2. Key Dependencies

| Package | Purpose |
|---------|---------|
| `laravel/framework ^13.8` | Core framework |
| `laravel/sanctum ^4.3` | API token authentication |
| `spatie/laravel-permission ^8.3` | Role-based authorization |
| `spatie/laravel-activitylog ^5.0` | Audit logging |
| `endroid/qr-code ^6.0` | QR code generation |
| `pestphp/pest ^4.7` | Testing framework |
| `larastan/larastan ^3.10` | Static analysis |
| `laravel/pint ^1.27` | Code style |
| `tailwindcss ^3.1` | Frontend CSS |
| `alpinejs ^3.4` | Frontend JS |
| `vite ^8.0` | Build tool |

---

## 3. Architecture Style

### Directory Architecture (Domain-Oriented within Laravel)

```
app/
  Actions/         — Business logic (Action pattern)
    Api/            — API token management
    Catalog/        — Catalog domain (Attributes, Categories, Documents, Lifecycle, Media, Products, Variants)
    Companies/      — Company/invitation management
    Passports/      — Passport domain (draft, publish, archive, restore, section management)
  Contracts/        — Interfaces
    Catalog/Integrity/ — Integrity check interfaces
    Passports/      — Passport contracts
  Data/             — DTOs/Value Objects
    Catalog/        — Catalog data structures
    Passports/      — Passport data structures (Localization, Public, Publication, Qr, Readiness)
  Domain/           — Domain exceptions and domain services
    Api/Exceptions/
    Companies/Exceptions/
    Invitations/Exceptions/
  Enums/            — Typed PHP 8.4 enums (29 total)
  Events/           — Domain events (4 passport events)
  Http/
    Api/            — API exception renderer, response
    Controllers/    — 63 controllers (Web + API)
    Middleware/     — 14 middleware
    Requests/       — 55 form requests
    Resources/      — 22 API resources
  Listeners/        — 3 audit listeners
  Models/           — 27 Eloquent models
  Notifications/    — 2 invitation notifications
  Policies/         — 12 authorization policies
  Queries/          — 4 query objects
  Security/         — Security utilities
  Services/         — 93 domain services
  Support/          — 11 support utilities
  Tenancy/          — Tenant resolution
  View/Components/  — Blade view components
```

### Key Architecture Patterns

1. **Action Pattern**: Controllers delegate to Actions. Both Web and API controllers use the same Actions.
   ```
   Web Controller → Application/Domain Action ← API Controller
   ```

2. **Tenant Isolation**: Every resource model has a denormalized `company_id`. All queries are company-scoped. Composite foreign keys ensure tenant-safe referential integrity.

3. **Immutable Snapshots**: Published passport versions and assets are immutable (enforced by MySQL triggers AND model events).

4. **Schema-as-Code**: DPP sections defined in `DppSchemaRegistry` service, not in database.

5. **Readiness as Code**: 66 rules defined in `config/passport_readiness.php` and implemented in `app/Services/Passports/Readiness/Rules/`.

6. **Hybrid Storage**: Passport payloads stored as JSON with normalized references to media/documents/assets.

---

## 4. Module Inventory

### Core Modules (R0)
| Module | Models | Tables | Routes |
|--------|--------|--------|--------|
| Authentication | User | users, password_reset_tokens, sessions | auth.php |
| Companies/Tenancy | Company, CompanyMembership | companies, company_user | web.php |
| Invitations | CompanyInvitation | company_invitations | web.php |
| Audit | AuditLog | activity_log | web.php |
| API Foundation | PersonalAccessToken | personal_access_tokens | api.php |
| Infrastructure | — | jobs, failed_jobs, job_batches, cache, cache_locks | console.php |

### Catalog Module (R1)
| Module | Models | Tables | Web Routes | API Routes |
|--------|--------|--------|------------|------------|
| Categories | Category | categories | 9 routes | 8 endpoints |
| Products | Product | products, category_product | 6 routes | 4 endpoints |
| Variants | ProductVariant | product_variants | 7 routes | 6 endpoints |
| Attributes | AttributeDefinition, AttributeOption | attribute_definitions, attribute_options | 12 routes | 12 endpoints |
| Attribute Values | ProductAttributeValue, VariantAttributeValue, ProductAttributeValueOption, VariantAttributeValueOption | 4 tables | 4 routes | 4 endpoints |
| Media | ProductMedia | product_media | 12 routes | 17 endpoints |
| Lifecycle | (actions on Product/Variant) | — | 10 routes | 7 endpoints |
| Search | — | — | 1 route | 1 endpoint |
| Audit | — | — | 2 routes | 1 endpoint |
| Operations | — | — | — | CLI commands |

### Passports Module (R2)
| Module | Models | Tables | Web Routes | API Routes |
|--------|--------|--------|------------|------------|
| Passport Core | ProductPassport | product_passports | 15 routes | 14 endpoints |
| Versions | ProductPassportVersion | product_passport_versions | 2 routes | 2 endpoints |
| Assets | ProductPassportAsset | product_passport_assets | — | — |
| DPP Authoring | — | — | — | — |
| Readiness | PassportValidationRun, PassportValidationResult | passport_validation_runs, passport_validation_results | 1 route | 1 endpoint |
| Publication | PublicationIdempotencyRecord | publication_idempotency_records | 6 routes | 4 endpoints |
| Public Delivery | — | — | 3 routes | — |
| QR | — | — | 3 routes | — |
| Documents | ProductDocument, ProductDocumentVersion | product_documents, product_document_versions | 8 routes | 9 endpoints |

---

## 5. Database Schema Summary

| # | Table | Rows in R2 | Primary Key | Tenant Key | Soft Delete | Immutable |
|---|-------|-----------|-------------|------------|-------------|-----------|
| 1 | users | base | id (bigint) | — | Yes | No |
| 2 | companies | base | id (bigint) | — | Yes | No |
| 3 | company_user | pivot | id (bigint) | company_id | No | No |
| 4 | company_invitations | base | id (bigint) | company_id | No | No |
| 5 | categories | catalog | id (bigint) | company_id | Yes | No |
| 6 | products | catalog | id (bigint) | company_id | Yes | No |
| 7 | product_variants | catalog | id (bigint) | company_id | Yes | No |
| 8 | category_product | pivot | id (bigint) | company_id | No | No |
| 9 | attribute_definitions | catalog | id (bigint) | company_id | No | No |
| 10 | attribute_options | catalog | id (bigint) | company_id | No | No |
| 11 | product_attribute_values | catalog | id (bigint) | company_id | No | No |
| 12 | variant_attribute_values | catalog | id (bigint) | company_id | No | No |
| 13 | product_attribute_value_options | pivot | id (bigint) | company_id | No | No |
| 14 | variant_attribute_value_options | pivot | id (bigint) | company_id | No | No |
| 15 | product_media | catalog | id (bigint) | company_id | Yes | No |
| 16 | product_passports | passport | id (bigint) | company_id | No | Partial* |
| 17 | product_passport_versions | passport | id (bigint) | company_id | No | Partial** |
| 18 | product_passport_assets | passport | id (bigint) | company_id | No | Partial*** |
| 19 | product_documents | document | id (bigint) | company_id | No | Partial* |
| 20 | product_document_versions | document | id (bigint) | company_id | No | Yes |
| 21 | passport_validation_runs | readiness | id (bigint) | company_id | No | Yes |
| 22 | passport_validation_results | readiness | id (bigint) | company_id | No | Yes |
| 23 | publication_idempotency_records | passport | id (bigint) | company_id | No | No |

*Identity columns (uuid, public_id, company_id, product_id) immutable via triggers.
**Published/superseded/withdrawn versions immutable. Draft versions mutable.
***Asset metadata immutable. Draft version assets deletable. Published version assets not deletable.

---

## 6. Permission Matrix (R2 Baseline)

### Company Roles
| Role | Description |
|------|-------------|
| Owner | Full control, manages members, billing (future) |
| Admin | Full catalog management, passport management, publication |
| Editor | Create/edit products, passports, documents |
| Viewer | Read-only access |

### Company Permissions
| Permission | Owner | Admin | Editor | Viewer |
|-----------|-------|-------|--------|--------|
| catalog.view | Yes | Yes | Yes | Yes |
| catalog.create | Yes | Yes | Yes | No |
| catalog.edit | Yes | Yes | Yes | No |
| catalog.delete | Yes | Yes | No | No |
| catalog.lifecycle | Yes | Yes | No | No |
| company.edit | Yes | No | No | No |
| members.view | Yes | Yes | No | No |
| members.manage | Yes | No | No | No |

### API Token Abilities (R2 Baseline)
| Ability | Description |
|---------|-------------|
| CompanyRead | Read company info |
| MembersRead | Read company members |
| CatalogRead | Read catalog resources |
| CatalogWrite | Create/update catalog resources |
| CatalogLifecycle | Activate/archive products |
| CatalogMedia | Upload/manage media |
| DocumentsRead | Read documents |
| DocumentsWrite | Create/update documents |
| DocumentsMedia | Download document content |
| PassportsRead | Read passport data |
| PassportsWrite | Write passport data |
| PassportsPublish | Publish passports |

---

## 7. Event Inventory (R2 Baseline)

| Event | File |
|-------|------|
| ProductPassportArchived | app/Events/Passports/ProductPassportArchived.php |
| ProductPassportPublished | app/Events/Passports/ProductPassportPublished.php |
| ProductPassportRestored | app/Events/Passports/ProductPassportRestored.php |
| ProductPassportUnpublished | app/Events/Passports/ProductPassportUnpublished.php |

All 4 events are in `app/Events/Passports/`. No listeners are registered for these events in R2 — they are dispatched but have no registered listeners (deferred to R3.14 for notification delivery).

---

## 8. Feature Flag Inventory (R2 Baseline)

No feature flags system exists in R2. All R2 capabilities are unconditionally enabled. R3 feature flags are introduced by `docs/architecture/adr/R3-010-feature-flags.md` and must remain separate from permissions and billing entitlements.

---

## 9. Known R2 Limitations (Carried to R3)

| # | Limitation | R3 Resolution Stage |
|---|-----------|-------------------|
| 1 | Single readiness profile (`nordipass-pilot` v1) with fixed weights | R3.2 |
| 2 | No DPP section versioning — schema v1 hardcoded | R3.3 |
| 3 | Documents have no compliance workflow (review/approval) | R3.4 |
| 4 | No category templates or required attributes | R3.5 |
| 5 | No Excel/CSV import or export | R3.6 |
| 6 | No ERP integration | R3.7 |
| 7 | No billing — all features free | R3.8 |
| 8 | No analytics | R3.9 |
| 9 | Only 4 roles — no granular permissions | R3.10 |
| 10 | Public passport is server-rendered Blade only | R3.11 |
| 11 | QR is single-size, no batch generation | R3.12 |
| 12 | API covers catalog/passports — no webhooks | R3.13 |
| 13 | No in-app notifications | R3.14 |
| 14 | No platform operations/support tools | R3.15 |
| 15 | CSP not enforced, no GDPR workflows | R3.16 |
| 16 | No structured observability | R3.17 |
| 17 | No guided onboarding | R3.18 |
