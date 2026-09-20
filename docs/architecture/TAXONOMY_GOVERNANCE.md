# Taxonomy Governance Architecture

## Versioned category templates

`category_templates` stores immutable numbered versions for a tenant-owned category. A draft version can contain required/recommended attribute assignments and DPP section defaults. Activation retires the previous active version, pins a registered readiness profile code/version/fingerprint, and updates `categories.active_template_id` atomically.

The database allows at most one active version per company/category through a nullable active slot and a unique key. Composite foreign keys prevent cross-company category, attribute, template, and product references. MySQL triggers reject changes to activated versions and reject child assignment/default changes unless the parent template is still a draft.

Activating a template does not create or change product attribute values, Passport payloads, drafts, published versions, or validation evidence. Products whose primary category changed template are marked `taxonomy_needs_review` and require an explicit acknowledgement.

## Attribute diagnostics

Template assignments have one of two requirements:

- `required`: missing values appear in `missing_required` and keep the product in needs-review state;
- `recommended`: missing values appear in `missing_recommended` but do not by themselves keep the product in needs-review state.

Product-scoped attributes require a product value. Variant-scoped attributes require a value on every non-archived variant. `both` accepts a product value or complete variant coverage. Diagnostics never invent values or copy DPP defaults into an existing product.

## Lifecycle

Attribute definitions and options use `active`, `deprecated`, and `archived`:

- active values can be selected for new assignments;
- deprecated values remain readable and count for existing assignments but are not selectable for new assignments;
- archived values remain historical/read-only and do not satisfy new selection or completeness checks.

Deprecation records tenant-safe replacement references. An attribute replacement must have the same type and scope. An option replacement must belong to the same definition. Existing product and variant foreign keys already use `ON DELETE RESTRICT`; the lifecycle service also returns an actionable validation error before deleting a referenced option.

## Controlled vocabularies

Only select and multiselect definitions can set `vocabulary_controlled`. New controlled options start as `pending`; pending/rejected options cannot be assigned to products or variants. Explicit approval records approver and timestamp. Existing uncontrolled options were backfilled as approved.

## Readiness profile resolution

New Passport drafts resolve their profile in this order:

1. explicit product override;
2. active template of the product's primary category;
3. system active readiness profile.

Every assignment stores code, version, and fingerprint. The resolver rejects a fingerprint that does not match the registered profile version. Existing draft and published Passport versions continue to use their already-pinned profile identity.

## Governance application layer

`TaxonomyGovernanceAction` is the audited authorization boundary for template creation/configuration/activation, product acknowledgement/profile override, attribute deprecation, option deprecation, and option approval. Controllers resolve every nested resource through the current company before invoking the action.

The catalog API exposes category-template versions, product taxonomy diagnostics, lifecycle replacements, and controlled-option approval under the existing `catalog.read`, `catalog.write`, and `catalog.lifecycle` abilities. The OpenAPI contract documents 94 operations.

The web application exposes the same contract through:

- a category template editor with draft cloning, requirements, validated DPP defaults, activation, and version history;
- a Product Needs Review card that links to missing attributes and only offers acknowledgement when required fields are complete;
- definition and option lifecycle controls that require explicit compatible replacements;
- approval state for controlled-vocabulary options.
