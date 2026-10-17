# Pilot readiness hardening — 14 October 2026

## Result and scope

Local implementation addresses the first four P1 findings in the
[13 October audit](2026_10_13_PASSPORT_BLOCKERS_AND_SUPPLIER_ACCEPTANCE.md).
The [pilot release scope](2026_10_14_PILOT_RELEASE_SCOPE.md) defines the next
release boundary. This is not production or mandatory DPP acceptance.

The working tree is based on `fe85adb955deee7cf18786063a9ecf302df0b99e`.
No commit, push, staging delivery, production deployment, provider request or
live payment was performed. Application changes are local and uncommitted.

## Behavior

- Readiness, the product/passport detail pages and publication confirmation
  display publication, sector requirements and EU registration independently.
  A score of 87 and zero publication blockers cannot turn an incomplete sector
  assessment or a local registry preparation into verified compliance/registration.
  EN/SV text is provided; stored assessments are explicitly dated.
- The existing pilot safety rule is accurately labelled **Safety information
  present**. Empty/whitespace list entries no longer count as information.
  A non-pilot profile's explicit safety-review requirement needs a referenced,
  approved document with review actors/dates and scope matching the passport,
  current draft revision and safety section. Another revision needs new evidence.
- Manufacturer contact uses manufacturer email/website/phone only. Operator
  contact cannot substitute for it. Separate manufacturer postal/phone/registration
  fields and importer identity/contact fields are supported by validation,
  normalisation, editing and published HTML/JSON. New draft changes do not rewrite
  earlier published party information.
- `DppPartyIdentityPresent` checks a typed manufacturer/importer/responsible-operator
  role selected by a profile. Name, postal address and contact are separate from
  organisation verification; supplied details are not marked verified.
- Required sector document/certificate checks reject empty evidence. Required
  sector composition checks need material rows. Optional pilot integrity checks
  retain their operational meaning. Explicit evidence policies can further
  constrain type, evidence purpose, party and draft revision; they cannot replace
  certificate evidence with unrelated documents or broaden a stricter type filter.
- Evidence must belong to the current company/product/document, match the pinned
  reference when present, have an approved available version and a usable file,
  recorded review/approval dates and valid dates for the evaluation. Matched
  document/version UUIDs, file checksum and decision dates are retained in the
  rule's safe context; raw document metadata and content are not copied there.
- Profiles requesting `metadata.publication_mode = regulatory` are blocked by
  `regulatory.publication.coverage` while complete sector/access/registry capability
  remains unimplemented. A metadata claim of complete coverage and warning
  acknowledgement do not bypass the action's publication gate.

No migration or dependency change is needed. Stored evaluations, publication
snapshots and audit rows are not backfilled or rewritten. The existing execution
manifest records changed implementation provenance for new evaluations.
`docs/API.md` and OpenAPI descriptions are updated; response envelopes and
existing error codes are preserved.

## Verification

- Final affected regression: **929 tests passed / 4,756 assertions**, exit 0,
  PHP 8.4.21 and dedicated MySQL testing. Includes unit DPP/readiness, authoring,
  publication, public HTML/JSON/assets/cache, QR, localisation, tenant security,
  historical evidence, API, approvals, product workspace and read-only billing
  display. Log: `storage/framework/pilot-readiness-20261014/regression-final.log`.
- Final PHPStan: zero errors. Pint check of all changed PHP files passes.
- Additional publication transaction/commit-acknowledgement and API documentation
  checks: **22 tests passed / 506 assertions**, exit 0. These test outer commit,
  rollback, savepoints, asset compensation and durable publication behavior.
- `npm run build` passes using the existing isolated Node **22.23.3** runtime.
- Final scoped `git diff --check` passes. Runtime logs and generated assets are
  not source inputs; unrelated pre-existing deployment changes/demo deletions
  were not included or reverted.

Earlier broad runs exposed the pilot/sector boundary regression and one authoring
fixture that removed manufacturer contact on its second version. These were
corrected before the passing final run. Profile-refresh/date tests now use narrow
identity fixtures, and a separate real `generic-espr` test verifies rejection
without required evidence. No runtime check was weakened to make those tests pass.

PHP's pre-existing duplicate-extension startup warnings remain. The restricted
Windows sandbox prevented PHP from opening the existing test bootstrap and Pint
from writing owned source files; authorized checks outside that sandbox passed.
This pass does not claim a fresh full `composer test`, browser viewport acceptance,
server acceptance or production billing test.

## Remaining release work

Accept an actual supplier document-review workflow with real independent approval
and appropriate sector facts. Resolve import source decimal formatting and mapping
reuse; make Fortnox full/incremental results explicit. Then accept the target
production queues/email/payment/webhooks, populated file recovery, production
deployment policy, final full quality gate and fixed executable release archive.
Full battery and EU Registry implementation remain a separate release scope.
