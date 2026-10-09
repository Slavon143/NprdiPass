# Battery mapping and EU registry preparation

The hosted passport, its published version and its EU registration are distinct
records. Publication continues to create immutable NordiPass snapshots. An EU
registration cannot be inferred from a public URL, a successful local validation
or EU Login sign-in.

## Battery catalogue

`config/battery_passport_guidance.php` retains all 71 numbered positions from
[Batteries Passport data points, edition 2.0, 15 August 2026](https://single-market-economy.ec.europa.eu/document/download/cd1e5e6c-4a4a-4b99-995a-49eb6916187e_en?filename=Digital+Batteries+Passport+-+data+point+by+category.pdf).
Source credit: European Union, 2026, CC BY 4.0. Labels are paraphrased; NordiPass
adds candidate mappings, access-domain groupings and implementation notes.
The publication explicitly states that it is non-authoritative and does not
represent the Commission's official position or introduce legal requirements.

Each position retains a regulation reference, source page, EV/LMT/industrial
guidance classifications and possible existing payload paths. Paths are
candidates requiring manufacturer evidence, semantic matching and unit checks.
The assessment returns metadata and presence flags, never underlying values.
Every `compliance_verified` flag remains false. Unmapped positions identify
implementation gaps, not necessarily missing mandatory data for every battery.

Positions 16 and 25 are repeated data; 51 is a dynamic observation rather than
an alias of initial capacity. Scope capacity in kWh does not satisfy capacity in
Ah. A manufacturer country does not identify a plant, an operator address does
not establish a manufacturer postal contact, and a generic document does not
prove conformity. Positions 45–71 identify restricted information domains; the
existing generic public fields are not an implementation of that access regime.

Guidance statements concerning future formats, due diligence and a pending
Omnibus amendment are retained as advisory notes. They do not disable readiness
checks, set statutory application dates or waive obligations. The independent
mandatory battery publication blocker remains in force.

## Current local preparation

`PrepareDppRegistryRegistration` admits a current published version under tenant
authorization, commercial admission and the managed-product gate. It locks the
passport and version, checks the submitted UUID against the publication pointer,
and copies its checksum. A composite database foreign key binds company,
passport and published version. Records are append-only, with model guards and
database triggers. An audit failure rolls back the insert.

The uniqueness boundary is published version plus registry environment. A replay
returns the original preparation without changing its URL or audit trail. A new
publication creates a new preparation; earlier records remain historical even
if the earlier publication is superseded. Acceptance and production records are
separate, but neither can submit externally. The native UI offers acceptance only.

Preparation status is always `blocked`; the EU `registration_uri` is always
null, enforced in the database. The hosted `passport_uri` is only a candidate
UPI. Syntax admission requires HTTPS, no credentials, query or fragment, and a
maximum of 2000 bytes. Invalid configured URLs are discarded and omitted from
audit properties. URI resolution and standards conformity remain unverified.

All preparations retain explicit blockers for unconfirmed organisation access,
unconfirmed official submission schema, absent transport and unverified UPI
conformity/resolution. These describe this integration's current state, not the
availability of every API or organisation in the EU service.

## Future external registration contract

The [Registry resources](https://single-market-economy.ec.europa.eu/single-market/digital-product-passport/resources-documentation_en)
and [economic-operator guide](https://single-market-economy.ec.europa.eu/document/download/a71b5d30-ed8b-4c27-809d-757cf5e6030f_en?filename=dpp_registry_user_guide_for_economic_operators.pdf)
are the starting points. The inspected guide is version 1.03, 16 September 2026;
it describes portal templates and outcomes, not a confirmed REST endpoint or
authentication contract. Do not derive an official JSON schema from UI labels.

Before implementing transport, obtain the official schema, authorised verified
organisation access and the documented authentication/endpoint contract.
Introduce separate immutable submission attempts and provider receipts linked
to a preparation, version checksum, environment and organisation identity.
Preserve provider correlation identifiers, processing/success/failure outcomes,
returned registration identifiers and safe error codes. Retry attempts must not
overwrite earlier evidence. Portal batch rejection is atomic according to the
guide: one failed item rejects the batch. Never mark individual items successful
without the corresponding provider outcome.

The guide's hosted UPI and returned Unique Registration Identifier must occupy
separate fields. Secrets and raw request/response bodies must not enter the
ledger, audit or public API. Transport acceptance needs a real or clearly
declared test operator/product and retained safe provider evidence. Local
synthetic tests cannot establish EU registration or sector compliance.

## Deployment

The additive migration `2026_10_09_000002` is required before opening the new UI.
This change does not run migrations against the normal application database.
The migration is exercised only through the isolated test suite. There is no
new API v1 endpoint and no change to the public passport serialization contract.
