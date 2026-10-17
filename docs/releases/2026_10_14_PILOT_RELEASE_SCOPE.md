# NordiPass pilot release scope — 14 October 2026

## Working product scope

Prepare a commercial pilot for companies managing product catalogues and
supplier documents. The existing electrical-product fixtures provide the first
acceptance workload; they do not establish a legally applicable sector DPP profile.

The first release includes company isolation and roles, catalogue import/export,
versioned documents and approval, editable passport drafts, operational readiness,
immutable publication, public pages and QR codes, and the existing onboarding and
commercial plan flows once accepted in the target production environment.

Sell catalogue/document organisation and publication with clear limits. A readiness
score, a published page or a QR code does not certify regulatory compliance.
Do not present EU registration as completed without an actual accepted receipt.

Full battery fields, instance lifecycle/telemetry, restricted-access domains,
verified sector coverage and EU Registry submission remain outside this pilot.
Additional ERP connectors are after release. The user restricted current Fortnox
work to the test environment on 14 October: permitted test subscription `1854146`,
with the working subscription `1853390` and other accounts out of scope. Production
Fortnox activation is not part of this preparation; existing test-account access
does not establish production readiness.

## Implementation sequence

1. Make checks and product claims accurate. Separate publication, sector and
   registration status; distinguish safety content from recorded review; separate
   manufacturer/importer/operator details; enforce explicitly required evidence.
2. Accept the intended customer data workflow. Address the retained ABB decimal
   input `0,0040` through explicit source-format handling and visible preview;
   make mapping reusable and Fortnox incremental/full progress understandable.
3. Accept production operation. Verify HTTPS/address, email delivery, database
   queue/cron processing on the chosen hosting plan, alerts, production-specific
   deployment hooks, populated media/passport asset recovery and commercial
   payment/webhook scenarios. Existing staging evidence is not production evidence.
4. Fix the final source revision, pass the complete release quality gate, build
   and verify the executable archive, and accept the selected update/recovery
   baseline. AGENTS.md requires explicit permission before commit or push.
5. Start a small paid customer pilot. Observe real onboarding, first import,
   first publication, support needs and willingness to continue paying; expand
   public availability after resolving pilot blockers.

## Completion boundaries

Implementation and test changes are local preparation until actually delivered
and accepted on staging. Production deployment, live billing and customer uptake
must be recorded separately. No stage becomes complete merely because another
stage has passing tests.

## Fortnox local verification — 14 October

The current change adds a bounded full-catalogue preview and explicit import
confirmation, distinguishes incremental empty results from an empty full scan,
and records scan mode, fetched pages and resumable progress. Existing mappings,
local overrides and conflict handling remain in the normal transactional applier.
The committed data watermark advances only after every page completes and cannot
move backwards. Preview confirmation is single-use, expires after ten minutes,
and checks the current actor, company, connection and committed watermark.

Verification passed locally: 148 Fortnox feature/unit tests with 618 assertions,
four release-schema/API-documentation tests with 57 assertions, PHPStan, scoped
Pint checks, the frontend production build and the final whitespace diff check.
Provider tests used the fake provider or mocked HTTP with stray requests blocked.
There were no real Fortnox calls, OAuth authorizations, subscription changes or
production operations during this work. The existing nonproduction account and
client guards were preserved; the new explicit full-preview/import flow also
refuses production before provider work.

The nullable scan-mode/page-count migration ran only in the isolated test
database. Historical runs retain unknown values. Changes remain uncommitted and
have not been deployed to staging. A target migration, cache behavior and the
protected Fortnox release-policy qualification still need delivery review and
acceptance. Browser/viewport acceptance and the full application test suite were
not run for this Fortnox block.

The [13 October audit](2026_10_13_PASSPORT_BLOCKERS_AND_SUPPLIER_ACCEPTANCE.md)
is the problem/evidence baseline. The [11 October staging acceptance](2026_10_11_STAGING_RELEASE_ACCEPTANCE.md)
and subsequent [attribute delivery](2026_10_11_ATTRIBUTE_READINESS_STAGING_RESULT.md)
remain dated evidence; this scope does not rewrite their results.
