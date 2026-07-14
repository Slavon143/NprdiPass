# NordiPass R1.4 — Category Management

**Stage:** R1.4  
**Date:** 2026-07-14  
**Database:** MySQL 8 for local, CI, production, and all database-backed tests

## Scope

R1.4 provides tenant-safe web management for the independent Category aggregate. It includes create, update, move, sibling reorder, archive, and restore operations. It does not add Product CRUD, API endpoints, drag-and-drop editing, user-facing hard deletion, or implicit Product/category relationship changes.

The UI is available at `/settings/catalog/categories` inside the existing authenticated, verified, selected/current Company, active membership, and active Company middleware group.

## Permissions and tenant boundary

All Company roles may list Categories through `catalog.view`. Only Owner and Admin may mutate Categories through `catalog.manage_categories`; Editor and Viewer receive no mutation controls and are denied by the backend.

Controllers resolve every Category and parent UUID with `Category::forCompany($currentCompany)`. A UUID belonging to another Company is therefore concealed as 404. Form Requests authorize through `CategoryPolicy`, while every Action independently repeats authorization through `CompanyAuthorizer`, including fresh membership, active User, active Company, and CurrentCompany checks.

Payload values never choose `company_id`, `depth`, lifecycle status, actor IDs, or deleted state. Those values come from trusted models and the authenticated actor.

## Actions

| Action | Managed operation | Locking and idempotency |
|---|---|---|
| `CreateCategoryAction` | Root/child creation, slug normalization, depth and Company limit | Locks the Company's Category rows; duplicate normalized slugs map to a safe domain error |
| `UpdateCategoryAction` | name, slug, description, sort order | Locks and re-reads the row; unchanged input writes neither row nor audit |
| `MoveCategoryAction` | Parent change and full subtree depth adjustment | Serializes structural mutations per Company; current parent is a no-op |
| `ReorderSiblingCategoriesAction` | Complete sibling-set reorder | Locks the Company Category set; assigns `10, 20, 30, ...`; current order is a no-op |
| `ArchiveCategoryAction` | `active` to `archived` | Locks fresh rows; repeated archive is a no-op |
| `RestoreCategoryAction` | `archived` to `active` | Requires an active parent and valid preserved depth; repeated restore is a no-op |

Mutation and audit writes share the same database transaction. MySQL duplicate-key driver code 1062 is translated into `CategoryOperationException` and never exposed to the UI.

## Hierarchy rules

`CategoryHierarchyService` is a side-effect-free domain/read service. It receives Company, Category, and an explicitly loaded tenant collection; it does not read CurrentCompany, authorize, start transactions, log audit events, or render UI.

The service provides ancestor IDs, descendant IDs, a subtree, relative subtree depth, cycle detection, and breadcrumbs. Traversal is iterative and cycle-guarded.

The single application limit is `CategoryHierarchyService::MAX_DEPTH = 5`, matching the MySQL `categories_depth_check` constraint (`depth` 0 through 5). Creation computes `parent.depth + 1`. Moving computes a depth delta and applies it to the moving Category and every descendant in one transaction. A Category cannot be moved under itself or any direct/deep descendant. An archived Category cannot be selected as a new parent.

R1 assumes at most 500 non-administratively-deleted Categories per Company. `MAX_CATEGORIES_PER_COMPANY = 500` is enforced for normal creation. This allows structural operations to use one stable `ORDER BY id FOR UPDATE` tenant set, avoiding complex recursive locking and ensuring concurrent moves/reorders cannot observe a partially changed tree.

## Slugs and ordering

Category slugs use `CatalogIdentifierNormalizer`: trim, ASCII transliteration, lowercase, hyphen separation, and maximum length 255. A blank create slug is generated from the name. Slugs are editable, but `UNIQUE(company_id, slug_normalized)` remains the race-safe final guarantee. Archived and administratively soft-deleted rows continue to occupy their normalized slug because the unique row is retained.

Reorder accepts the complete UUID set for exactly one sibling scope (including root as a scope). Duplicates, omissions, a different parent, and a different Company are rejected. Successful reorder uses deterministic increments of ten. Reads use `sort_order`, then `name`, then `id` as a stable fallback.

## Archive and restore policy

Archive is a lifecycle state change, not deletion. It is blocked when either condition is true:

- the Category has an active direct child;
- the Category is `primary_category_id` for an active Product.

Archive never reparents children, deletes `category_product` rows, clears a Product primary Category, or chooses a replacement primary Category. Draft Product pointers and secondary pivot assignments remain unchanged. The operator must explicitly resolve blockers first.

Restore preserves the slug, parent, depth, and Product relationships. A child cannot be restored while its parent is archived or missing. The parent must be restored first; restore does not silently move the child to root.

No hard-delete Action, route, or UI control is present in R1.4.

## HTTP and UI

| Method | Route name | Purpose |
|---|---|---|
| GET | `catalog.categories.index` | Bounded list with status, parent/root, and name/slug filters |
| GET | `catalog.categories.create` | Create form with active tenant parents |
| POST | `catalog.categories.store` | Create Category |
| GET | `catalog.categories.edit` | Edit, move, archive/restore screen |
| PATCH | `catalog.categories.update` | Update managed scalar fields |
| PATCH | `catalog.categories.move` | Move to a tenant parent or root |
| PATCH | `catalog.categories.reorder` | Reorder a complete sibling set |
| PATCH | `catalog.categories.archive` | Archive without deletion |
| PATCH | `catalog.categories.restore` | Restore lifecycle state |

The index is paginated at 50 rows and eager-loads parent plus aggregate blocker counts; Products are never loaded. Parent option queries are bounded by the documented Company Category limit. Edit excludes self, descendants, archived Categories, and foreign Company Categories from the parent selector. Move up/down controls submit a complete sibling order without a frontend dependency.

## Audit events

R1.4 emits:

- `catalog.category.created`: Category UUID, name, slug, parent UUID, depth;
- `catalog.category.updated`: Category UUID and changed field names only;
- `catalog.category.moved`: Category UUID, old/new parent UUIDs, old/new depths, descendant count;
- `catalog.category.reordered`: parent UUID, ordered Category UUIDs, count;
- `catalog.category.archived` and `catalog.category.restored`: Category UUID and before/after status.

Descriptions and raw requests are not recorded. The existing audit sanitizer still processes all metadata.

## Database and application guarantees

MySQL continues to guarantee the Company-scoped normalized-slug uniqueness, composite same-Company parent FK, depth/status/sort checks, and insert/update self-parent triggers created in R1.2. Those triggers are the final direct-self-parent defense; arbitrary cycle and subtree-depth validation remain application guarantees because they span multiple rows.

R1.4 application code guarantees permission checks, explicit tenant resolution, active-parent rules, deep-cycle prevention, subtree depth consistency, full sibling-set reorder, archive blockers, idempotent no-op behavior, actor assignment, and transactional audit.

## Tests and CI

All Category and catalog tests run on the dedicated MySQL database `nordipass_testing`; SQLite is not configured or supported. The test bootstrap rejects non-MySQL drivers and database names without the `_testing` suffix.

Focused commands:

```bash
php artisan test tests/Feature/Catalog/Categories tests/Unit/Catalog/Categories
php artisan test tests/Concurrency/CategoryConcurrencyTest.php
php artisan test tests/Feature/Catalog tests/Unit/Catalog
php artisan test
```

The existing GitHub Actions backend job starts MySQL 8.0 and executes the complete standard suite, so R1.4 Action, structural, route, UI, and concurrency tests are mandatory CI tests without driver-based skips.

## Deferred to R1.5+

- Product management and Category assignment workflows;
- public or token-authenticated Catalog API endpoints;
- Category hard-delete/purge administration;
- drag-and-drop tree editing;
- recursive CTE optimization beyond the R1 size assumption;
- bulk import/export and localization.
