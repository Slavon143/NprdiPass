# R3.4.1 Readiness Editor Audit

## Scope

This audit covers all 66 readiness rules, their editor destinations, default-language data resolution, structured repeater fields, applicability, user-facing English copy, and publication severity. The goal is that every failed result tells the editor what is missing and opens the field that can resolve it.

## User-facing issue resolved

An empty Materials & Composition section previously produced three simultaneous warnings:

1. add product materials;
2. not all materials have percentages;
3. no recycled-content information is provided.

Only the first message was actionable while no material rows existed. Under active profile v2, an empty list now produces one failed warning, `dpp.materials.present`; percentage and recycled-content checks are `not_applicable`. Once at least one row exists, the two follow-up checks become applicable and point to the same materials repeater.

The generic suffix `Warning — does not block publication` was also replaced in the editor with `Optional for publication; completing it improves readiness`. Section counters use `suggested improvements`, so the UI no longer repeats the same severity fact after every message.

## Audited field contracts

| Area | Decision | Editor destination |
| --- | --- | --- |
| Materials present | One prompt when the repeater is empty | `materials_and_composition.materials` |
| Material shares | Applicable only after a row exists; `0` is supplied | `materials_and_composition.materials` |
| Recycled content | Applicable only after a row exists; recycled `0` is valid | `materials_and_composition.materials` |
| Usage and care | Accepts default-language text or structured steps | `usage_and_care.usage_instructions`, `care_instructions` |
| Repair instructions | Reads default-language translated content | `repair_and_spare_parts.repair_instructions` |
| Spare parts | Accepts URL, notes, or structured parts; `No` availability is a declaration | `repair_and_spare_parts.spare_parts` / availability gate |
| Take-back | An unset Yes/No gate asks for a declaration; `Yes` without details asks for details; `No` passes | availability gate, then `take_back_program` |
| Warranty | Same declaration/details behavior as take-back | availability gate, then warranty summary/URL |
| Support | Accepts structured support channels | `support_and_contact.support_channels` |
| Environmental metrics | Accepts the structured metrics repeater and numeric zero | `environmental_information.environmental_metrics` |
| Environmental claims | Accepts structured claims; review is N/A without claims and passes when every claim is reviewed | `environmental_claim_records` |
| Recycling/disposal | Action-oriented prompt points directly to instructions | `recycling_and_disposal.recycling_instructions` |
| Manufacturer contact | Result points to the concrete email field | `manufacturer_and_operator.manufacturer_email` |
| Safety review | Result points to warnings/review content | `safety.warnings` |

## Copy and navigation audit

- Every registered rule has English title, passed copy, and failed copy.
- Context-specific message keys take precedence over generic status copy.
- Take-back and warranty distinguish a missing declaration from missing details.
- Materials row controls have visible labels for material name, product share, recycled content, and country of origin.
- Ambiguous boolean gates and structured repeaters include inline help.
- Every rule section/field target is validated against `DppSchemaRegistry` by an automated UI contract test.
- Rules that cannot be fixed in the Passport editor retain their canonical catalogue/document destination.

## Automated evidence

| Check | Result |
| --- | --- |
| Focused readiness/editor/infra suite | 130 tests, 1,240 assertions, passed |
| Full PHP suite | 2,156 tests; 2,155 passed; 1 skipped; 8,685 assertions |
| Fresh MySQL migrations | 39 migrations, passed |
| PHPStan | Passed, 0 errors |
| Pint | Passed |
| Frontend build | Passed |
| Browser acceptance | 18 responsive pages, 11 workflow steps, 1 accessibility check, 3 security checks; all passed |

The one skipped test is the production backup artifact test. It requires an actual backup artifact and is tracked as an operational release condition, not as readiness-editor coverage.

## Verdict

The Passport editor readiness contract is accepted for the R3.4.1 release-candidate baseline. Profile v2 is the active draft profile; profile v1 evidence and published Passport snapshots remain immutable.
