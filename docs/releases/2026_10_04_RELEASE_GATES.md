# Release gates — 2026-10-04

For the current remaining-work checklist, see [2026-10-05 release plan](2026_10_05_RELEASE_PLAN.md). The subsystem table below is retained as historical evidence; it is not a count of six unfinished development tasks.

Update 2026-10-05: the user explicitly accepted the documented braces build/development risk for this release; see [risk decision](2026_10_05_DEV_DEPENDENCY_RISK.md). Historical audit findings below remain unchanged; the risk decision is now closed for this release, not a claim that the vulnerability was fixed.

This is a local release-verification record, not production deployment approval. Application behavior was not changed by this pass. Temporary acceptance helpers, owned sandbox fixtures and isolated MySQL schemas supplied the evidence below. Prior application changes and complete-suite qualification remain in [commercial acceptance](2026_10_04_COMMERCIAL_ACCEPTANCE.md).

## Status by stage

| Stage | Verified | Still required |
| --- | --- | --- |
| Target runtime | Current local schema contract passes. Linux deployment/archive tooling: 20 executable tests pass, no skips. | Actual staging address and operator access; proxy, service identities, storage locks, supervisor, scheduler, readiness and rollback using site hooks. |
| Billing | Real sandbox monthly renewal, invoiced upgrade, immediate downgrade, failed renewal, stable grace, expired-grace restriction and recovery; real annual renewal and separate monthly usage window. | Target webhook endpoint and lifecycle delivery; customer portal and scheduled change acceptance with target configuration. |
| Fortnox | Real HTTP read refreshed an expired access token once; scoped mutex held; catalogue fingerprint unchanged. | Representative remote pagination; applied sync, conflict handling and interrupted/restarted sync on staging. |
| Imports | Current commercial Business fixture, actual CSV preview/apply with 250-row chunks and capacity checks. See measured results below. | Representative supplier XLSX corpus and full mapping on the target. |
| Readiness/documents | 1000 managed products, drafts and persisted readiness evaluations at a 100k catalogue passed. Earlier automated domain/storage coverage remains applicable. | Full managed-product and retained-document workload, document processing and recalculation on target hardware. |
| Release operations | Existing local backup/isolated restore/migration rehearsal; current read-only schema verification; Linux lock/drain/atomic deploy/rollback tooling. | Backup/restore, migration, worker restart and smoke rehearsal with actual deployment hooks; production configuration and commercial activation. |

## Provider lifecycle evidence

All Stripe objects created here belong to the explicitly selected sandbox account and have `livemode=false`. No live charge occurred. These checks used real Stripe Test Clocks, invoices and subscription API responses, followed by the production reconciliation action in an owned isolated database.

- Monthly Starter: initial and renewed invoices paid EUR 49; authoritative plan allowance stayed 100.
- Growth upgrade: real prorated invoice paid EUR 99.73, projected allowance 1000. Immediate Starter downgrade with no proration returned allowance to 100; this does not prove a scheduled downgrade or portal flow.
- Declining test payment method: real renewal invoice remained open, amount paid zero. Reconciliation projected `past_due`; a repeated reconciliation did not extend grace. Advancing the sandbox clock beyond grace reduced the writable product allowance to zero.
- Restoring the valid test method and paying the owned invoice recovered `active`, allowance 100 and cleared grace.
- Annual Starter: initial and second-year invoices paid EUR 490. Moving into the next month changed the operational usage window without another invoice or changing the annual billing period.
- Both newly created subscriptions were canceled without another invoice or proration. The two earlier acceptance subscriptions are also canceled. Retained test customers/clocks are evidence fixtures, not active subscriptions.

The lifecycle checks above exercise reconciliation, not fresh HTTP delivery for each scenario. The separate earlier evidence proves four distinct actual webhook deliveries, duplicate handling, signature rejection and interrupted-inbox recovery through the temporary CLI listener. It does not prove a persistent staging endpoint.

Fortnox real acceptance observed one article on one page and exactly one token refresh. Product rows did not change; no import/sync apply ran. The normal local connection's encrypted credentials were updated by ordinary authenticated token rotation. One remote article cannot establish real pagination or bulk sync recovery.

## Local workload evidence

Measurements use this Windows development host and its shared local MySQL service. They are not a production SLO. Notifications and queues are faked; actual transactional preview/apply actions run sequentially. CSV has four mapped core fields. Business commercial v1 entitlements are used without raising quota: 10000 managed / 100000 total catalogue pool. Imported products are unmanaged.

| Rows | Apply + final integrity | Chunks | SQL statements | PHP peak | Integrity |
| --- | --- | --- | --- | --- | --- |
| 10000 | 124.925 s | 40 | 220816 | 52 MiB | 10000 products, 10000 variants, no skipped rows; total/managed counters 10000/0 |
| 100000 | 1245.016 s (20 min 45 s) | 400 | 2208016 | 62 MiB | 100000 products, 100000 variants, no skipped rows; total/managed counters 100000/0 |

The initial 10k helper run imported successfully but failed its final assertion because an uninitialized managed counter can legitimately remain unknown (`NULL`). That harness failure is retained. The reported clean run initializes the counter with the real reconciler before measuring; no application fix was needed.

The measured write cost is approximately 22 SQL statements per product. Maximum measured chunk time is 3.566 s at 10k and 5.341 s at 100k. This establishes bounded memory and successful integrity under this workload; it does not establish acceptable throughput for a target deployment. Optimize only after profiling the actual write path and checking preservation of transaction, audit and capacity boundaries.

Readiness sample after the 100k import: 1000 products were enrolled through the real managed action, given drafts through the real draft action, evaluated with the production context builder/evaluator, then recorded through the validation-run action. Total time was 110.134 s, PHP peak 60 MiB, 114007 SQL statements. Enrollment took 9.969 s, draft creation 23.967 s, evaluation 29.069 s and result persistence 47.066 s. Exactly 1000 drafts, 1000 validation runs and 66000 individual rule results were persisted; managed/total counters were 1000/100000. All incomplete drafts remained `not_ready` with blockers and nonempty ruleset provenance; no passport was published.

These bare products have no category-specific supplier data and all resolve the configured `nordipass-pilot` profile. This sample does not establish alternative profile selection, full 10k readiness throughput or a document-heavy workload. Existing focused profile/ruleset regressions remain separate evidence.

## Dependency/security qualification

Composer audit reports zero advisories and zero abandoned dependencies. Production npm audit (`--omit=dev`) reports zero vulnerabilities. The full npm audit reports five high findings in a development dependency chain, all attributable to one underlying [braces stack-exhaustion advisory](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm). The authoritative advisory still lists no patched version on 2026-10-04. No dependency upgrade or risk acceptance is implied by this record; a compatible mitigation/upgrade or explicit release decision remains required.

The previous complete suite retains its original one-failure result. Its sole Windows Python-launch failure passed on unchanged code with the explicit installed interpreter. See commercial acceptance for the exact counts and the separately verified final cleanup exit-status change.

## Target and input discovery

### Follow-up with newly supplied files

The user subsequently supplied two XLSX files. Their original bytes were accepted through isolated preview/apply, including typed attributes and invalid-row accounting; the synthetic PDF/version/review workload and document-heavy readiness also completed post-run verification. See [file acceptance](2026_10_04_FILE_ACCEPTANCE.md) for exact counts, retained harness failures and scope. The files are production-like test fixtures, not independently verified supplier material. Supplier-provenance and staging gates remain open.

This follow-up found and fixed silent removal of unsupported mapping targets. They now produce a validation error before altering an existing preview. Native variant SKU/GTIN/MPN support was subsequently implemented; see [native identifier acceptance](2026_10_04_NATIVE_IDENTIFIER_ACCEPTANCE.md). The supplied 5000-row workbook has only 500 checksum-valid GTINs: the native-identifier run accepted 500 rows and rejected 4500. A separate generated valid 1000-row fixture passed CSV/XLSX export and update reimport without duplicate variants. Earlier CSV identifier coverage was corrected in the companion acceptance record. Target staging and authenticated supplier acceptance remain open.

The next follow-up explicitly defines source status/timestamp/document-reference behavior and shows unconsumed columns in web preview. No lifecycle transition, timestamp override, URL fetching or document attachment is implied. See [source metadata acceptance](2026_10_04_IMPORT_SOURCE_METADATA_ACCEPTANCE.md) for verified boundaries, including the limitation that source timestamps do not reject stale updates.

Local category/profile acceptance now exercises two independent synthetic profiles from imported category selection through commercial enrollment, frozen drafts, requirement results and persisted validation evidence. It also fixes incomplete override fall-through, unknown fallback ruleset fabrication and inactive fallback compatibility bypass. See [DPP profile selection acceptance](2026_10_04_DPP_PROFILE_SELECTION_ACCEPTANCE.md). This does not establish supplier provenance, automatic jurisdiction/product-scope classification or legal coverage.

Read-only authenticated GitHub discovery found zero repository environments, zero repository variables and zero deployments. Available workflow/site-hook examples do not expose a staging address or usable operator access. Available local browser inventory has no existing tabs; local SSH/config and attachment/download discovery did not identify staging access or a representative supplier XLSX corpus. Two development CSVs were found; neither substitutes for supplier XLSX acceptance.

Recent remote CI failures refer to older commits, not the present uncommitted work. This pass did not push, dispatch deployment, create hosting, provision paid services or activate production billing.

## Evidence

Artifacts are in the commercial acceptance directory linked in the companion record:

- `github-staging-discovery-20261004.json`
- `stripe-release-lifecycle-evidence.jsonl`, monthly invoice evidence
- `stripe-release-annual-evidence.jsonl`, annual invoice evidence
- `fortnox-real-read-acceptance.json`
- `commercial-scale-10000-verified.log`, `commercial-scale-10000-result.json`
- `commercial-scale-100000-verified.log`, `commercial-scale-100000-result.json`
- `commercial-readiness-scale.log`, `commercial-readiness-scale-result.json`
- `release-provider-evidence-summary.json`: assertions over retained provider results, not additional executions
- `release-tooling-linux-20261004.log`: 20 passed, zero skipped; Windows counterpart has 12 passed / 8 platform skips and is not counted as 20 passed
- `release-current-schema-20261004.log`
- `release-composer-audit.json`, `release-npm-audit.json`, `release-npm-production-audit.json`

Private fixture manifests, credentials and database backups must not be published wholesale.
