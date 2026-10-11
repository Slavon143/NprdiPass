# Attribute warning and empty editor follow-up — 11 October 2026

The user reported that the Product attributes warning led to an editor containing only Cancel and Save attributes. The rule previously treated an absence of stored values as a failure regardless of whether the tenant had any active product-scoped fields to fill in. Rule IDs and safe-context JSON also appeared in expanded requirement cards and distracted from the user's next action.

## Correction

- The optional operational attribute rule applies to active Product/Both definitions in the current company. With no eligible fields it is not applicable, has no action link and does not lower the score. Archived definitions and fields belonging to another company or only to variants do not make it applicable.
- Once fields exist, missing values still produce a warning linked to the editor containing those fields. Actual values for active definitions satisfy the check. Historical archived values do not satisfy active fields.
- Explicitly required DPP profile requirements remain failures when definitions are absent, with a configuration action instead of a link to an empty form. A regression verifies that deleting/omitting definitions cannot bypass a required profile requirement. Publication boundaries and immutable published snapshots remain unchanged.
- Empty editors now explain that there are no configured fields, offer Create attribute only under the existing definition-creation policy, and provide a return link. No meaningless Save button appears.
- Requirement cards contain the human-readable problem and action. Warning cards state that they do not block publication. Rule IDs, requirement metadata and JSON diagnostics are retained only inside the initially closed Technical details section.
- The catalogue list fetches eligible tenant definition IDs once and passes them in an internal typed evaluation context. No request field or editable requirement configuration establishes tenant context or supplies these IDs. This avoids one extra query per product.

## Verification and implications

- **102 tests / 681 assertions passed on PHP 8.4.21**, including the fictional visual capture. Coverage includes the reported empty editor, active/archived/variant/foreign definitions, actual values, required-profile enforcement, score calculations, query-count bounds, historical reproducibility, readiness purity, API, commercial read-only paths and workspace presentation.
- PHPStan: zero errors. Affected Pint and whitespace checks passed. Node 22.23.3 frontend build passed.
- Browser inspected the actual Blade/controller rendering on fictional test data. Empty fields have a visible explanation and creation/return links; requirement cards have zero Rule details blocks and the diagnostic container is closed. These local fixtures use rolled-back test data and do not submit forms.
- Fresh evaluation may produce a different score/warning count because an inapplicable optional attribute check is removed from the denominator. Stored historical evaluations and published passport snapshots are not rewritten. No database migration or external request is introduced.
- This follow-up is local and uncommitted when written. The already deployed Product Workspace version is `cb76517010bdae5ce7da7b292311f57d3f71e1fe`; it does not include this later warning correction. Another local commit and staging candidate must be approved before delivery. No push, production deployment, paid transaction or OAuth is part of this follow-up.

Reviewed file scope and hashes are in `storage/framework/attribute-readiness-20261011/proposed-attribute-commit.json`. Temporary visual fixtures, screenshots, backups and unrelated local changes are excluded.
