# R3.3 Advanced DPP Sections

## Summary

R3.3 expands the existing DPP JSON schema contract with advanced materials, recycled content, environmental, repair, recycling, warranty/support, responsible-operator, and compliance metadata fields. It preserves the accepted R3.1/R3.2 architecture:

- one DPP pipeline;
- schema-as-code in `DppSchemaRegistry`;
- backend normalization and validation;
- existing draft revision workflow;
- existing readiness engine and `nordipass-pilot` v1 rule set;
- immutable published snapshot/public/API flow.

## Compatibility

`nordipass-pilot` v1 was not semantically changed. Rule classes, rule order, severity, weights, algorithm, and profile version remain unchanged. The R3.2 canonical fingerprint remains expected to be:

```text
f668cbb32defc4b23420a129970ec9233c8cb330905898ce2206e37583611569
```

The only compatibility-sensitive domain decision made in this stage is preserving recycled content as a percentage of each material, not a percentage point share of the full product.

## Implemented Changes

| Area | Change |
|---|---|
| Schema | Added advanced fields to accepted broad DPP sections |
| Normalization | Canonical decimal strings; structured JSON-list normalization; stable ordering |
| Validation | Decimal string validation; safe URL checks; JSON-list required/controlled fields; payload/list/string/locale bounds |
| Editor | Generic JSON-list textarea for advanced structured arrays; decimal fields submit strings |
| Public Passport | Advanced materials, metrics, claims, repair, recycling, warranty/support, and compliance metadata rendering |
| API/OpenAPI | DPP field type enum includes `json_list` |
| Tests | DPP unit tests and focused passport feature/readiness slices updated and passing |

## Non-goals Preserved

No certificate approval workflow, document review workflow, expiry notification engine, maker-checker approval, official legal certification, or automated legal conclusion was added. Compliance metadata is metadata only.

## Residual Acceptance Status

Fresh MySQL migration, seeders, R3.2 compatibility, backfill N/A, audit metadata, public/API serialization, payload bounds, publication immutability, Pint, PHPStan, build, and the full automated suite have current passing evidence in `docs/testing/R3_3_DPP_TEST_EVIDENCE.md`.

The release verdict remains rejected because the mandatory npm and Composer registry advisory audits were denied by the environment security reviewer, and browser/manual responsive accessibility evidence was not completed in this pass. R3.4 must not begin from this state.
