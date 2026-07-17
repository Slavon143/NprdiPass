# DPP Editor Field Matrix — Canonical Contract

## Storage Rules

- **Translatable fields**: `translations.{locale}.{section}.{field}`
- **Non-translatable fields**: `data.{section}.{field}`
- **Document references**: `document_references` (logical UUIDs in draft, pinned version UUIDs in snapshot)

## Section Translatability

ALL sections are translatable (support mixed fields — some translatable, some not).

| Section | Section key | Translatable |
|---|---|---|
| Identity | `identity` | yes |
| Manufacturer & Operator | `manufacturer_and_operator` | yes |
| Origin & Traceability | `origin_and_traceability` | yes |
| Materials & Composition | `materials_and_composition` | yes |
| Safety | `safety` | yes |
| Usage & Care | `usage_and_care` | yes |
| Repair & Spare Parts | `repair_and_spare_parts` | yes |
| Recycling & Disposal | `recycling_and_disposal` | yes |
| Environmental Information | `environmental_information` | yes |
| Certifications & Documents | `certifications_and_documents` | yes |
| Support & Contact | `support_and_contact` | yes |

## Complete Field Inventory

### Identity (translatable)
| Field | Type | Translatable | Required | Max length |
|---|---|---|---|---|
| `public_name` | ShortText | yes | no | 500 |
| `public_description` | LongText | yes | no | 5000 |

### Manufacturer & Operator (translatable)
| Field | Type | Translatable | Required | Max length |
|---|---|---|---|---|
| `manufacturer_display_name` | ShortText | yes | no | 500 |
| `responsible_operator_display_name` | ShortText | yes | no | 500 |
| `contact_notes` | LongText | yes | no | 5000 |
| `manufacturer_email` | Email | no | no | — |
| `manufacturer_website` | Url | no | no | — |
| `responsible_operator_email` | Email | no | no | — |
| `responsible_operator_website` | Url | no | no | — |
| `manufacturer_country` | CountryCode | no | no | — |
| `responsible_operator_country` | CountryCode | no | no | — |

### Origin & Traceability (translatable)
| Field | Type | Translatable | Required | Max length |
|---|---|---|---|---|
| `country_of_origin` | CountryCode | no | no | — |
| `manufacturing_countries` | StringList | no | no | 3 (per item), 50 items |
| `production_date` | Date | no | no | — |
| `traceability_notes` | LongText | yes | no | 5000 |
| `batch_identification_instructions` | LongText | yes | no | 5000 |

### Materials & Composition (translatable)
| Field | Type | Translatable | Required | Max length |
|---|---|---|---|---|
| `materials` | MaterialList | no | no | 100 items |
| `composition_notes` | LongText | yes | no | 5000 |

MaterialList item shape:
- `name` (ShortText, required)
- `percentage` (Decimal, 0–100, optional)
- `recycled_content_percentage` (Decimal, 0–100, optional)
- `hazardous` (Boolean, required, default false)
- `country_of_origin` (CountryCode, optional)

### Safety (translatable)
| Field | Type | Translatable | Required | Max length |
|---|---|---|---|---|
| `warnings` | StringList | yes | no | 1000 (per item), 100 items |
| `hazards` | StringList | yes | no | 1000 (per item), 100 items |
| `personal_protective_equipment` | StringList | yes | no | 1000 (per item), 100 items |
| `storage_instructions` | LongText | yes | no | 5000 |
| `emergency_instructions` | LongText | yes | no | 5000 |
| `age_restrictions` | ShortText | yes | no | 500 |

### Usage & Care (translatable)
| Field | Type | Translatable | Required | Max length |
|---|---|---|---|---|
| `usage_instructions` | LongText | yes | no | 5000 |
| `care_instructions` | LongText | yes | no | 5000 |
| `maintenance_instructions` | LongText | yes | no | 5000 |
| `storage_recommendations` | LongText | yes | no | 5000 |

### Repair & Spare Parts (translatable)
| Field | Type | Translatable | Required | Max length |
|---|---|---|---|---|
| `repairable` | Boolean | no | no | — |
| `spare_parts_available` | Boolean | no | no | — |
| `spare_parts_url` | Url | no | no | — |
| `repair_instructions` | LongText | yes | no | 5000 |
| `disassembly_instructions` | LongText | yes | no | 5000 |
| `spare_parts_notes` | LongText | yes | no | 5000 |
| `service_information` | LongText | yes | no | 5000 |

### Recycling & Disposal (translatable)
| Field | Type | Translatable | Required | Max length |
|---|---|---|---|---|
| `recycling_instructions` | LongText | yes | no | 5000 |
| `disposal_instructions` | LongText | yes | no | 5000 |
| `take_back_program` | LongText | yes | no | 5000 |
| `recycling_codes` | StringList | no | no | 50 (per item), 50 items |

### Environmental Information (translatable)
| Field | Type | Translatable | Required | Max length |
|---|---|---|---|---|
| `carbon_footprint_kg_co2e` | Decimal | no | no | min: 0 |
| `recycled_content_percentage` | Decimal | no | no | min: 0, max: 100 |
| `expected_lifetime_years` | Decimal | no | no | min: 0 |
| `energy_consumption_kwh` | Decimal | no | no | min: 0 |
| `environmental_claims` | StringList | yes | no | 1000 (per item), 50 items |
| `environmental_notes` | LongText | yes | no | 5000 |

### Certifications & Documents (translatable)
| Field | Type | Translatable | Required | Max length |
|---|---|---|---|---|
| `certification_notes` | LongText | yes | no | 5000 |
| `compliance_summary` | LongText | yes | no | 5000 |

### Support & Contact (translatable)
| Field | Type | Translatable | Required | Max length |
|---|---|---|---|---|
| `warranty_summary` | LongText | yes | no | 5000 |
| `support_email` | Email | no | no | — |
| `support_phone` | ShortText | no | no | 50 |
| `support_url` | Url | no | no | — |
| `warranty_url` | Url | no | no | — |
| `support_notes` | LongText | yes | no | 5000 |
