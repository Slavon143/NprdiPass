# Advanced DPP Sections

## Status

R3.3 expands the accepted R3.1 DPP schema-as-code model. The source of truth remains the `product_passport_versions.payload` JSON document, normalized by `DppPayloadNormalizer`, validated by `DppPayloadValidator`, evaluated by the existing readiness engine, and copied into immutable published passport versions by the existing snapshot builders.

No parallel DPP storage, editor backend, public store, API business logic, or readiness engine is introduced.

## Section Contracts

The stable technical section codes remain the accepted ADR-R3-003 section ownership codes:

| Section code | R3.3 advanced coverage |
|---|---|
| `materials_and_composition` | Materials, per-material recycled content, renewable percentage metadata, origin, source, notes, ordering |
| `environmental_information` | Recycled content summary, environmental metrics, environmental claims, review-state metadata, source metadata |
| `usage_and_care` | Usage instructions, usage steps, usage warnings, care instructions, care steps, care warnings |
| `repair_and_spare_parts` | Repair information, repairability declaration, skill level, tools, repair time, spare parts |
| `recycling_and_disposal` | Recycling instructions, disassembly guidance, waste/material codes, sorting guidance, hazard notes, take-back program |
| `support_and_contact` | Support channels, warranty metadata, warranty conditions, claim instructions, support notes |
| `manufacturer_and_operator` | Responsible operator legal/contact/source metadata |
| `certifications_and_documents` | Compliance metadata layer and compliance summary |

The implementation keeps the 11 accepted broad DPP section codes to preserve compatibility with R2/R3.1 public rendering, readiness fix links, and historical payloads. The user-facing advanced concepts are represented as stable field contracts inside those sections.

## Ownership And Inheritance

| Field/section | Owner | Fallback | Override | Published representation |
|---|---|---|---|---|
| Responsible operator | Company/Product | Company/manufacturer when explicitly entered as source metadata | No variant override | Resolved scalar metadata in payload |
| Materials | Product | Future category template defaults from R3.5 | Variant material override is reserved by ADR, not separately persisted in R3.3 | Ordered material objects |
| Usage/care | Product | Future category template defaults from R3.5 | No variant override in R3.3 | Locale-layered instruction fields |
| Repair/spare parts | Product | Future category template defaults from R3.5 | No variant override in R3.3 | Repair fields plus ordered spare part objects |
| Recycling/take-back | Product | Future category template defaults from R3.5 | No variant override in R3.3 | Locale-layered instructions plus safe URLs |
| Warranty/support | Company/Product | Company support defaults may be copied into draft by authoring workflow | No variant override in R3.3 | Support and warranty fields |
| Compliance metadata | Product | None | No variant override | Metadata only; no approval workflow |

The normalizer preserves meaningful `false` and `0` values. Decimal values are canonicalized as strings so JSON snapshots are deterministic and do not depend on binary float representation.

## Validation And Normalization

Validation remains backend authoritative. The editor performs collection only; it does not decide domain validity.

Important constraints:

- decimals are accepted as decimal strings or numbers and normalized to canonical strings;
- URLs allow public `http`/`https` only and reject credentials, localhost, private IPs, and reserved IPs;
- material names are required and duplicate material names are rejected;
- material percentages must be between 0 and 100 and total material percentage must not exceed 100;
- recycled content percentage is treated as a percentage of the material, preserving accepted R2/R3.2 semantics;
- structured JSON-list fields require arrays of objects, enforce required keys, and support controlled value lists where configured.
- payload size is capped at 1 MiB;
- full-payload translation locale fan-out is capped;
- string lists, material lists, document references, and structured JSON lists have explicit item limits;
- structured JSON-list string values have an explicit per-value length cap.

## Localization

Sections marked translatable continue to use:

```text
payload.translations.{locale}.{section}.{field}
```

Non-translatable fields stay under:

```text
payload.data.{section}.{field}
```

Public rendering layers shared data, default locale translations, and requested locale translations in that order.

## Readiness Mapping

`nordipass-pilot` v1 is not changed. Existing rules continue to map to the accepted broad section codes:

| Rule area | Source section |
|---|---|
| Materials valid/present/percentages/recycled content | `materials_and_composition` |
| Environmental claims/metrics/review | `environmental_information` |
| Usage/care | `usage_and_care` |
| Repair/spare parts | `repair_and_spare_parts` |
| Recycling/take-back | `recycling_and_disposal` |
| Warranty/support | `support_and_contact` |
| Responsible operator | `manufacturer_and_operator` |

Adding fields did not add, remove, reorder, or re-weight rules in `nordipass-pilot` v1.

## Serialization And Publication

The canonical payload is built by the existing normalizer and snapshot builders. Published versions store the resolved payload and catalog context in `product_passport_versions.payload`, with existing publication immutability protections. Public Passport rendering and API resources read the immutable version payload, not mutable catalog or draft data.

## Public Representation

Public partials render the advanced fields when present:

- materials show code, percentage, recycled content, origin, source, and notes;
- environmental information shows structured metrics and claim records;
- repair shows manufacturer-provided repair information, required tools, and spare parts;
- recycling shows take-back details, disassembly/sorting/hazard guidance, and waste codes;
- support shows support channels and warranty metadata;
- documents/compliance shows compliance metadata.

Private source metadata is not exposed unless it is explicitly stored in public DPP fields.

## Audit Contract

R3.3 keeps the accepted R2/R3.1 generic Passport audit event contract. Draft section changes emit `passport.draft.updated` with section metadata instead of duplicate section-specific events. The canonical event properties include product UUID, passport UUID, draft version UUID, old revision, new revision, `section_key`, and `changed_sections`.

Audit properties must not include the full payload, full section data, document content, secrets, credentials, or private URLs.
