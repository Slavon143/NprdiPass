# Passport readiness architecture

## Purpose

Passport readiness is a versioned, explainable publication decision. It is not a regulatory certification and the percentage alone never authorizes publication. A passport is publishable only when every applicable blocker rule passes.

## Versioned profile

The authoritative profile is `config/passport_readiness.php`:

- profile: `nordipass-pilot`
- active profile version: `2`
- active rule-set version: `2`
- score algorithm version: `1`
- maximum evaluation query budget: `20`
- required core sections: identity, manufacturer/operator, safety, recycling/disposal

Every validation run persists those identifiers. Changing rules, weights, grouping, applicability, or scoring requires incrementing the appropriate version; historical evidence must not be reinterpreted using new configuration. Profile v1 remains registered as deprecated with fingerprint `f668cbb32defc4b23420a129970ec9233c8cb330905898ce2206e37583611569`. New and editable drafts use profile v2 with fingerprint `cba5078e465bba66f53ffcb613a7975a9ca4d5c8dd7888c72dcac988bd95e5b5`; published v1 snapshots are not migrated.

R3.2 adds an immutable rule-set fingerprint to the evaluated result. The fingerprint is calculated from the pinned profile code/version, rule-set version, score algorithm name/version, severity weights, and the registered PHP rule codes/classes/groups/severities. Runtime scoring uses the resolved profile definition, not the mutable top-level compatibility weights.

## Scoring

Weights are blocker `10`, warning `3`, and recommendation `1`.

For each applicable rule:

- passed: its severity weight is included in earned and applicable points;
- failed: its severity weight is included only in applicable points and in lost points for that severity;
- not applicable: excluded from both point totals but retained in counts/evidence.

The score is:

```text
round(earned_points / applicable_points * 100)
```

It is clamped to 0..100. If no rules are applicable, the score is 100. Duplicate rule codes or non-positive/missing weights are rejected. Publication eligibility is:

```text
no failed blocker rules
```

Warnings and recommendations reduce the score but do not block publication. Non-applicable rules do not reduce the score.

Profile v2 also makes editor applicability explicit:

- an empty materials list produces one actionable warning to add materials; percentage and recycled-content follow-ups are not applicable until a row exists;
- a declared `No` for take-back, warranty, repairability, or spare-parts availability is a complete declaration rather than missing information;
- when a gate is `Yes`, the follow-up result points to the details field;
- default-language translations and structured repeaters are evaluated through the same canonical field view as the editor;
- numeric zero is treated as supplied data for percentages and environmental metrics.

## Evaluation path

1. `ReadinessContextBuilder` loads a tenant-scoped catalog/passport/draft context with normalized payload and resolved document/media state.
2. The configured registry executes each rule once and produces code, group, severity, status, user-facing message, fix URL, and safe context.
3. `ReadinessScoreCalculator` produces score, earned/applicable points, N/A count, weights, and lost points by severity.
4. `RecordPassportValidationRun` writes the immutable run and per-rule results.
5. Web/API presenters expose user-safe results. Technical codes are shown only in debug mode.
6. Publication obtains a database row lock, reloads the passport/draft, evaluates again, records evidence, and rejects any failed blocker.
7. The published version retains the evidence identity used for that immutable snapshot.

## Evidence model

`passport_validation_runs` records company, product, passport, draft UUID/revision, prospective passport version, profile/version, schema, rule-set version, rule-set fingerprint, score algorithm name/version, weights, earned/applicable points, score, counts, evaluator identity, and evaluation time.

`passport_validation_results` records the immutable per-rule result linked to its run. MySQL triggers reject update/delete operations on evidence rows. Evidence is append-only; a later draft evaluation creates a new run.

## Rule inventory

There are 66 rules: 33 blockers, 32 warnings, and 1 recommendation.

| Group | Count | Rule codes |
|---|---:|---|
| Catalog | 9 | `catalog.product.exists`, `active`, `name.present`, `identifier.present`, `brand.present`, `manufacturer.present`, `category.present`, `default_variant.present`, `attributes.present` |
| Passport | 14 | `passport.exists`, `status.editable`, `current_draft.exists`, `belongs_to_passport`, `status`, `schema.supported`, `payload.valid`, `payload.size`, `default_language.enabled`, `core_sections.enabled`, `revision.valid`, `optional_sections.none`, `languages.default_supported`, `languages.enabled_unsupported` |
| Identity | 3 | `dpp.identity.name.present`, `description.present`, `catalog_name_overridden` |
| Manufacturer | 4 | `dpp.manufacturer.name.present`, `contact.present`, `country.present`, `dpp.responsible_operator.present` |
| Safety | 3 | `dpp.safety.reviewed`, `emergency_information.present`, `storage_information.present` |
| Recycling | 3 | `dpp.recycling.instructions.present`, `codes.present`, `dpp.take_back_program.present` |
| Technical | 9 | materials valid/present/percentages/recycled content, usage/care instructions, repairability/repair instructions, spare-parts information |
| Environmental | 3 | claims present, metrics present, claims review |
| Media | 5 | primary present/belongs/available, gallery present, variant coverage |
| Documents | 6 | references valid, current version valid, file available, metadata valid, public candidate present, referenced current version |
| Certificates | 5 | metadata complete, not expired, expiring soon, no expiration, declaration present |
| Support | 2 | support channel and warranty information |

The exact ordered class registry remains authoritative in `config/passport_readiness.php` and is protected by `ReadinessRuleRegistryTest`.

## UI contract

The readiness page must:

- show Ready/Not Ready and explain that blockers, not the percentage, control publication;
- show earned/applicable points and failed counts/lost points per severity;
- keep Needs attention open and Passed/Not applicable collapsed;
- group rules by user domain;
- show Source, Current, Result, Requirement level, and a tenant-safe Fix link;
- keep technical profile/run/revision details collapsed;
- hide technical rule codes in non-debug environments;
- retain the legal disclaimer that NordiPass does not certify product compliance.

## Preview and public separation

Draft preview uses the same normalized snapshot builder and renderer as the public passport but remains authenticated and company-scoped. It emits `private, no-store` and `noindex,nofollow`, uses authenticated asset URLs, displays a clear draft banner, and creates no publication, public asset, QR, or validation side effect.

The public resolver accepts only the current immutable published version and publication assets. It must never read mutable draft/catalog rows to render already published content.

## Verified examples

| Fixture | Passed | Failed blockers | Failed warnings | Failed recommendations | N/A | Points | Score |
|---|---:|---:|---:|---:|---:|---:|---:|
| Traffic signals equivalent | 34 | 8 | 19 | 0 | 5 | 277 / 414 | 67% |
| Reflective Safety Vest | 42 | 3 | 16 | 0 | 5 | 336 / 414 | 81% |

Both examples were correctly Not Ready. Historical expectations based on 65 rules are stale.
