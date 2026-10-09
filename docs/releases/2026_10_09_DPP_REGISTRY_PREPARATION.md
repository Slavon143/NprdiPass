# Battery catalogue and EU registry preparation evidence

Implemented on 2026-10-09. NordiPass now has a versioned internal battery mapping
catalogue and an immutable local registry-preparation ledger. No external
registration, organisation enrolment, payment, deployment, commit or push was
performed. Normal application database migrations were not run.

## Result

All 71 positions of the August 2026 EU battery guidance are retained with source
pages, regulation references and EV/LMT/industrial classifications. Labels are
paraphrased and NordiPass mappings are additions under CC BY 4.0. The publication
is advisory, not an authoritative statement of legal obligations.

26 positions have possible existing payload sources, 2 positions repeat earlier
data, and 43 have no dedicated mapping. These counts describe implementation
coverage, not a compliance score or a mandatory-field count. Candidate presence
never becomes verified compliance. Initial capacity, scope capacity in kWh and
dynamic capacity remain distinct. Guidance deferrals do not waive legal duties.
Restricted battery information is not added to public passport serialization.

The new native page is linked from passport readiness. Battery mappings assess
the current draft when present, otherwise the current publication; the page
identifies the assessed version. Registration preparation always targets the
current published version supplied by UUID. A ledger entry retains the tenant,
passport, version, checksum, environment, hosted URL candidate and blockers.

Repeated requests for one publication/environment return the original record.
Republishing creates a separate record and earlier records remain immutable.
Tenant authorization, managed-product and commercial write admission, row locks,
a composite tenant/version foreign key, audit rollback and SQL immutability
triggers protect this boundary. Status remains `blocked`, and the EU-issued
registration identifier is always null, including production-environment local
records. No adapter, endpoint, credentials or official submission schema was
invented.

## Validation

- New focused tests: 10 passed, 127 assertions.
- Passport feature, DPP unit, passport API and OpenAPI specification suites:
  660 passed, 2474 assertions.
- PHPStan: passed, zero errors.
- Pint: passed for the ten touched PHP files.
- Vite production build: passed.
- Final whitespace/diff checks: passed.

Regression coverage exercises battery category differences, duplicate and dynamic
positions, candidate data without compliance claims, cross-tenant rejection,
stale version rejection, viewer/unmanaged rejection, environment separation,
idempotence, audit failure rollback, republishing, SQL tampering and the native
page. Fixtures are explicitly synthetic; no real battery acceptance is claimed.
The PHP runtime emitted existing duplicate-extension warnings. No failing check
remained in the executed suites.

## Remaining external gates

EU Login and the Acceptance Registry Help Center were reached during this task;
the observed UI reported version 1.0.12. The user is an independent developer
without a registered business. Organisation verification and actual DPP
registration access remain unconfirmed. These are different from sign-in.

Before external registration, obtain a verified authorised organisation, the
official payload template/schema and transport/authentication contract. Then
implement separate submission attempts and safe provider receipts, including
correlation identifiers, atomic batch rejection and the EU-issued registration
identifier. The independent mandatory-battery publication blocker remains until
complete Annex XIII data, access controls and registration are accepted.

The additive migration `2026_10_09_000002_create_dpp_registry_preparations_table`
must be applied through the normal release process before using the new native
page. It was exercised only in the isolated test database. See
[architecture and integration boundaries](../architecture/DPP_REGISTRY_PREPARATION.md)
and [API documentation](../API.md#dpp-applicability-and-public-data).

Sources:
[battery guidance, edition 2.0](https://single-market-economy.ec.europa.eu/document/download/cd1e5e6c-4a4a-4b99-995a-49eb6916187e_en?filename=Digital+Batteries+Passport+-+data+point+by+category.pdf),
[registry resources](https://single-market-economy.ec.europa.eu/single-market/digital-product-passport/resources-documentation_en),
[economic-operator guide](https://single-market-economy.ec.europa.eu/document/download/a71b5d30-ed8b-4c27-809d-757cf5e6030f_en?filename=dpp_registry_user_guide_for_economic_operators.pdf).
