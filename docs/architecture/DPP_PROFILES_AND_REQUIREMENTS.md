# DPP profiles and requirements

## Application dates and publication freshness (2026-10-09)

Requirements additionally record source_version and inclusive effective_from /
effective_to dates. Their applicability is evaluated on the assessment date,
including for frozen drafts. A future requirement without a handler is deferred;
it fails closed once applicable. Reversed windows fail closed. Publication
integrity checks cannot be deferred by a profile. New fields are included in
requirement conflict signatures and regulatory profile fingerprints; historical
pilot configuration fingerprints are preserved.

Frozen snapshots remain the history/authoring contract. Direct readiness also
compares the complete ordered selection and requirement definitions with the
current product selection when either selection contains a non-pilot profile.
Changes, unavailable profiles and regulatory drafts without snapshots
block publication. During publication the current profile/requirement rows are
read under shared locks. An explicit settings refresh captures the current
selection in a new draft revision, invalidating approvals based on older revisions.
It never changes older validation runs or published versions. Product-list batches
omit the live comparison to retain their bounded query contract; publication
always uses direct construction and the transactional check.

Optional regulatory_context in the draft payload records operator-declared market,
product type, capacity, model/item identity and classification evidence. It is
validated through tenant-authorized settings, preserved by normalization and frozen
in publication. Raw classification evidence is not written into audit properties
or rendered by the public resolver. It does not establish tenant context.

The scope assessor distinguishes EV, LMT, industrial and portable batteries. The
strict >2 kWh threshold applies only to industrial batteries. Article 77 scope is
upcoming before 2027-02-18 and mandatory from that date for newly placed batteries.
This implementation cannot certify full Annex XIII coverage, registry integration
or restricted stakeholder access: mandatory declared battery scope therefore adds
an independent blocker even under an operational profile. It cannot be disabled
through profile rules or warning acknowledgement. Earlier declared market events
are distinguished from later remanufacturing or repurposing. Missing dates cannot
establish a retrospective exemption; after the deadline covered categories with
missing dates receive an assessment blocker. No exemption or full regulatory
coverage is certified by operator-declared facts.

Electrical products receive sector_assessment_required; classification alone
never invents a sector act, battery obligation or legal compliance. Lack of
classification remains explicit not_assessed rather than invented applicability.

The domain path is product → applicable profiles → versioned requirements →
evaluation evidence → readiness → immutable publication. `nordipass-pilot`
remains a compatibility profile; it is not the executor for every profile.

## Concepts

- DppProfile is a versioned, persisted profile with jurisdiction, product
  scope, lifecycle status, and metadata.
- DppRequirement is one requirement in a profile. Its level is explicitly
  required, conditional, or recommended; this is separate from the readiness
  result severity: blocker, warning, or recommendation.
- A requirement also records applicability context, regulatory source,
  source reference, mapped readiness rule, and a safe action target.

Applicability is represented independently from severity. A readiness rule can
therefore be not applicable without turning a conditional requirement into a
warning or blocker.

## Profile resolution

DppProfileResolver resolves profiles in this order:

1. explicit product assignments, ordered by assignment priority;
2. the product/category readiness profile and version;
3. the exact profile key and version configured as the fallback.

Explicit assignments must be active and inside their effective dates. An
unknown explicit version fails validation rather than silently choosing a pilot.
Product overrides must contain both a nonempty key and a positive version;
an incomplete persisted override fails validation rather than falling through
to category or fallback selection. An unknown configured fallback cannot
fabricate a definition using the global operational rules. Known configuration-only
legacy profiles remain supported. Frozen draft snapshots bypass live selection.
An existing inactive persisted fallback is rejected rather than treated as an
absent catalogue row and re-enabled through configuration compatibility.
Every assigned profile is retained. The first assignment determines the score
definition; requirements from all assignments determine readiness.

The current selector checks lifecycle status and effective dates. Jurisdiction
and product_scope are descriptive profile metadata, not an automatic legal
classification engine. Category and explicit product assignments supply the
selection policy. Synthetic profile fixtures cannot establish sector coverage
or replace acceptance against authenticated supplier data.

EffectiveDppRequirementSet merges the resolved profiles by requirement key.
Identical duplicates are collapsed deterministically. Conflicting definitions
fail closed with a LogicException; silent regulatory ambiguity is not allowed.

## Readiness integration

Each effective requirement executes its mapped rule class or registered rule.
Missing/mismatched handlers and unsupported evaluation configuration produce
failed blockers. Required failures block, recommended failures are
recommendations, and conditional requirements preserve rule severity.
`when_section_enabled` provides explicit applicability; `context_config`
provides requirement-local configuration. Publication integrity blockers cannot
be weakened through level, applicability, configuration, or class replacement.
Duplicate rule codes get distinct result identifiers when requirements differ.

Results retain requirement key/level, selected profile/version, source,
source reference, assignment/applicability reasons, and checked document
version UUIDs. Internal readiness pages/API expose regulatory metadata.
Public passport data does not expose the internal requirement catalogue.

New drafts freeze all selected profiles, requirements, score definition, and
evaluation configuration in `readiness_profile_snapshot`. Readiness restores
that snapshot without consulting a changed live profile catalogue. Validation
runs retain configuration, individual results, and provenance. Publication
pins the document versions selected by its readiness evaluation, then rechecks
those exact pins; it does not independently select a newer version.

This freezes configuration and stored results, not executable PHP code. Legacy
drafts without snapshots retain their compatibility resolution path. Old
published evidence is not rewritten or backfilled with invented provenance.

New draft snapshots also capture an execution manifest. Readiness evidence
records the current manifest independently: application source digest,
Composer lock digest, PHP version, and actual container-resolved handler
classes/source digests. `execution_comparison` is `matched`, `changed`, or
`legacy_unknown`; missing historical provenance is never inferred from current
code. The manifest declares `replay_guarantee: provenance_only`. It identifies
a build, but does not archive or execute historical PHP. Retain deployment
artifacts and restart long-lived workers when deploying code. An implementation
change does not silently rewrite old validation runs or block all existing drafts.

Legacy catalogue lookups are batched for one product-list page. The batch is
discarded after that page and preserves live compatibility semantics. Direct
evaluation still resolves fresh catalogue data; no legacy snapshot is invented.

The legacy pilot profile keeps its historical configuration fingerprint contract.
That fingerprint alone does not identify the executable implementation.
New regulatory profiles include their
persisted requirement catalog in the rule-set fingerprint.

The migration seeds:

- nordipass-pilot v2 as the operational compatibility profile;
- generic-espr v1 as deliberately limited EU pilot coverage, not legal
  certification or complete ESPR coverage.

Future profiles should be added as new versions or new keys. Existing
published/history records must continue to resolve the profile version they
were created with.


## Legacy execution policy and executable retention

Drafts created before snapshots existed continue to resolve the live catalogue.
Their missing historical execution provenance remains `legacy_unknown`.
Do not backfill it from today's catalogue or handlers. Creating a new draft is
the explicit way to freeze a current configuration; reassessing a legacy draft
retains its compatibility behavior and records the current execution manifest.
Stored validation results and published evidence remain unchanged.

New release archives contain the executable application, locked production
dependencies, compiled frontend, and exact per-file hashes. RELEASE.json records
the application digest using the same canonical mapping as the DPP execution
manifest. Deployment retains the verified archive in a content-addressed server
directory before migrations. GitHub artifact retention is only a transfer window.
An operator-managed off-host copy and a tested recovery policy are required;
deployment never automatically prunes executable archives.

This preserves builds from the first archived release onward. It cannot recover
missing older binaries. The runtime manifest deliberately continues to declare
`replay_guarantee: provenance_only`: restoring a build is not an implemented,
isolated historical replay engine or a guarantee that historic external data,
document selection, OS/runtime, and database state can be reconstructed. If
implementation changes, a new evaluation records `changed` and the current
handler digests while leaving earlier evidence intact.
