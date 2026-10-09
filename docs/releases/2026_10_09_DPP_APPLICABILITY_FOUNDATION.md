# DPP applicability implementation and acceptance

As of 2026-10-09, NordiPass has an operational passport foundation with dated
requirements, checks against current regulatory profiles, declared product
classification and public JSON. Electrical supplier samples and synthetic
batteries exercise this foundation. Full sector compliance and EU Registry
registration remain incomplete; this release does not certify an ESPR or battery
passport.

## Implemented behavior

Requirements record the regulatory source version and inclusive application
dates. Future and ended requirements are not applicable on the assessment date;
a required handler missing when a requirement applies blocks publication.
Invalid date windows fail closed, and dates cannot disable publication integrity
checks. Evaluation evidence retains the source version, dates and assessment date.

When current or frozen selection contains a regulatory profile, direct readiness
compares all selected profiles and their requirements with the frozen draft.
Changed or unavailable definitions block publication. Publication reads current
profile rows under shared locks. An explicit authorized refresh captures current
definitions and advances the draft revision; older evaluations and publications
remain unchanged. Product-list previews retain their bounded query contract and
omit the live comparison.

The readiness screen and passport settings API accept validated classification
facts: market, product type, capacity, model or item identity, classification
source, lifecycle event and event date. Mutations enforce tenant authorization,
draft revision and managed-product admission. Audit records identify the mutation
without copying raw classification evidence. Failure to write the audit rolls
back the classification and profile refresh.

Battery scope distinguishes EV, LMT, industrial and portable batteries. Article
77 covers EV and LMT batteries and industrial batteries strictly above 2 kWh
from 2027-02-18. The capacity threshold does not apply to EV or LMT batteries.
This follows [Regulation EU 2023/1542, Article 77](https://eur-lex.europa.eu/eli/reg/2023/1542/ojn).
Declared earlier market events are distinguished from later remanufacturing or
repurposing. Missing event dates cannot establish an earlier-event exception;
covered categories receive an assessment blocker after the deadline. Facts are
operator declarations requiring independent acceptance.

Covered batteries whose declared scope is mandatory receive an independent
publication blocker because complete Annex XIII data, stakeholder access and
registry integration are not yet implemented. Warning acknowledgement cannot
bypass this blocker. Electrical classification records that a sector assessment
is still required and leaves registration applicability undetermined. Product
classification alone does not establish delegated-act coverage under
[ESPR, Articles 4 and 9](https://eur-lex.europa.eu/eli/reg/2024/1781/oj).

The canonical public URI supports `Accept: application/json` with format
`nordipass-public-passport-v1`. It serializes the existing public allowlist from
the published snapshot, including public asset links, and excludes draft data,
tenant identifiers, internal evidence and storage keys. HTML and JSON use
separate ETags with `Vary: Accept, Accept-Encoding`. This custom interchange
format has not been accepted by the EU Registry and does not establish EN
conformity. The [Commission registry guidance](https://single-market-economy.ec.europa.eu/single-market/digital-product-passport/dpp-registry_en)
remains the external integration contract.

## Acceptance for both product groups

Three authentic identity records from retained Hager, Berker and Elcom public
XLSX files were copied into a small fixture with source filenames, row numbers
and SHA-256 digests. The regression exercises actual CSV preview and apply,
commercial company creation, explicit managed enrollment, draft classification,
readiness and persisted validation evidence. Missing product passport evidence
keeps these products not ready and prevents publication. This is acceptance of
three derived identities, not complete acceptance of the original XLSX files or
their entire catalogues. The Elcom sample is a screw classified as other; it is
not represented as an electrical device.

The user has no real battery for acceptance. Clearly synthetic fixtures cover
EV and LMT batteries, industrial capacity below, equal to and above 2 kWh,
portable batteries, the deadline, earlier events, later lifecycle events and
missing dates. These verify the application behavior; they do not substitute
for a real manufacturer's model, instance, technical data or evidence.

EU Login test sign-in and Registry Help Center access were subsequently confirmed
on 2026-10-09 (Acceptance UI 1.0.12). Organisation verification and DPP registration
access remain unconfirmed. The user is an independent developer without a
registered business; no economic-operator identity was invented.
No real external registration was attempted or fabricated. An accepted registry
request and response, unique identifier linkage and failure handling remain an
external acceptance gate.

## Verification

The expanded run passed **315 tests and 1623 assertions** across DPP unit tests,
publication, readiness, performance, public passports, passport API and catalogue
overview query budgets. A subsequent focused run after two additional regression
cases and native refresh-form coverage passed **38 tests and 149 assertions**.
The runs overlap and must not be added as unique test counts. After the final
OpenAPI 3.1 schema edits, documentation checks passed **3 tests and 39 assertions**.

The focused cases cover application windows, immutable history, stale profile
blocking, explicit refresh, revision checks, tenant and role boundaries, audit
rollback, battery publication blocking, supplier imports and public JSON cache
and disclosure boundaries. PHPStan reported zero errors; scoped Pint, the Vite
production build and selected-file whitespace checks passed. These checks do not
replace a complete repository quality gate or staging acceptance.

Database tests use `nordipass_testing`. The additive migration
`2026_10_09_000001_add_dpp_requirement_application_dates.php` was exercised there;
it still needs to run in the deployment environment before the new application
code. Normal tenant data and original supplier files were not changed. No commit,
push or deployment was performed. The checkout already contained extensive
uncommitted work; the whole checkout diff is not attributable to this change.

## Remaining implementation

Electrical products need maintained, legally applicable sector profiles with
actual sources, dates, mappings and supplier evidence. Batteries need complete
Annex XIII data for a real instance, stakeholder access, dynamic data and
lifecycle links, followed by real registry integration and acceptance. The
[Commission battery FAQ](https://single-market-economy.ec.europa.eu/single-market/digital-product-passport/eu-digital-product-passport-faq-batteries_en)
provides the domain guidance for that work. Public JSON, application dates and
scope classification are foundations for these steps, not proof they are done.
