# Forensic remediation — 2026-10-02

Status: confirmed defects below have regression coverage and local fixes. This
is release evidence for a dirty working tree, not a deployment or production
incident report. Existing user changes were preserved; no commits or pushes
were made.

## Verified behavior

| Area | Corrected boundary | Main implementation | Regression evidence |
|---|---|---|---|
| Publication | Commit success cannot trigger destructive cleanup; ancestor rollback cleans only the failed attempt | `PublishProductPassport`, `PublicationAssetStager`, `PublicationPostCommitException` | Real MySQL commit, observer connection, nested rollback/savepoints, injected listener/cache/relation failures |
| Public data | JSON-LD cannot close its script tag; published model guard reads original enum status correctly | `PublicPassportResolver`, `ProductPassportVersion` | Script-breakout payload round trip; ORM and trigger immutability checks |
| Documents | Tenant/product-scoped exact eligible pin, missing bytes block publication, readiness choice survives a newer version appearing | `ProductDocumentCurrentVersionResolver`, `ReadinessContextBuilder`, `PassportSnapshotBuilder`, publication action | Missing/unknown/expired pin, old valid pin, foreign resource, after-evaluation replacement regression |
| Idempotency | Authorization and tenant/resource identity precede cached results | `PublishProductPassport` | Viewer cannot retrieve cached success; company-prefixed cache keys and foreign resources |
| Approval | Approval decision, publication and audit share one transaction | `ApprovePassportPublicationApprovalAction` | Approval-write failure rolls back publication and its assets |
| Imports | Authorized state transition before queueing; job/chunk/row boundaries; bounded continuation; worker context restored | `StartImportJobAction`, `ApplyImportJobAction`, `PreviewImportJobAction`, `ApplyImportJob` | Viewer denial without writes, terminal duplicates, cold/stale worker, cancellation, chunk failure/retry, one async continuation |
| Fortnox | Session mutex across provider IO; atomic page cursor/watermark; safe retry and conflict resolution | `FortnoxSyncMutex`, `SyncFortnoxArticlesAction`, `FortnoxSyncService`, `FortnoxArticleApplier`, `ResolveFortnoxConflictAction` | Page failure rollback, resume across triggers, maximum timestamp, rejected article retry, changed local conflict value |
| DPP | Actual independent requirement execution, all assigned profiles, configuration freeze and provenance | `DppProfileResolver`, `DppRequirementEvaluator`, `DppDraftProfileSnapshot`, readiness evaluator, validation recorder | Independent profile, multiple profiles, unknown handler/configuration, level/applicability, immutable historical configuration, integrity bypass rejection |
| Outbound webhooks | Same-company subscribed outbox in domain transaction; outer commit dispatch; DNS pin; persisted retry/lease and recovery | Domain producers, `WebhookDeliveryService`, `DeliverWebhookJob`, `WebhookUrlSafety`, `RecoverWebhookDeliveriesCommand` | Real outer rollback sends nothing, actual producer payloads, private/redirect/rebinding guards, network failures, duplicate job claim, interrupted worker recovery |
| Performance/telemetry | Streaming XLSX export, bounded import cells, page-batched document readiness, reused eager relations, honest unknown metrics | `StreamingXlsxWriter`, `ImportFileReader`, `ProductIndexReadinessProvider`, readiness rules, `RequestTelemetry`, `ObservabilitySnapshotService` | Native XLSX round trip, sparse/chunk/formula cases, 1 vs 25 products query budget and score equality, unsupported queue metrics |
| Capacity/secrets | Unlimited plans skip catalogue scan; finite capacity retains company lock; OAuth secret fields redacted and Fortnox payload hidden | `CatalogProductCapacity`, `SensitiveDataSanitizer`, `FortnoxConnection` | Concurrent capacity check, no unlimited scan, secret serialization/redaction |

## Verification

- PHP 8.4, Laravel 13.19, MySQL 8.0.46; constraints and triggers enabled.
- Final complete suite: **2,481 passed, 10,592 assertions**, about 410 seconds.
- Final webhook retry-budget/history follow-up: **29 passed, 531 assertions**,
  including the real commit/rollback publication suite. An interrupted fifth
  attempt is closed and dead-lettered without sending a sixth request.
- Full application/database/routes PHPStan: **0 errors**.
- Pint `--test` on the remediation file manifest: **passed**. Formatting was
  generated in temporary copies and transferred back after Windows denied
  direct formatter writes; unrelated dirty files were not formatted.
- Vite `npm run build`: **passed**.
- The first full Composer invocation hit its 300-second process timeout. The
  verified invocation set `COMPOSER_PROCESS_TIMEOUT=0`; no success is inferred
  from an interrupted run.
- Broad testing exposed two obsolete UI assertions (billing email instead of
  user email; obsolete onboarding label) and two hard-coded testing database
  names. UI assertions now check the current user email and onboarding route;
  database safety still requires MySQL, testing environment and a dedicated
  `nordipass…_testing` schema. Restore SQL is checked against the actual schema.

Tests used isolated `nordipass_audit_fixes_20261002_testing` and disposable
concurrency schemas. Storage/cache paths were isolated under
`storage/framework/audit-fixes-20261002`; HTTP/queues were faked where external
delivery could occur. Production migrations, passports and integrations were
not executed.

Evidence logs, JUnit, before/after regression runs, approved patches, formatter
manifest and baseline copies are stored outside the repository at:

`C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/remediation/`

## Measured XLSX boundary

Fresh CLI process per size; five string columns, generated unique fixtures,
512 MiB PHP limit. Every SKU, row number, price and row count was verified.
PHP allocator peaks do not include all native libxml/ZIP memory or OS RSS.

| Rows | Write seconds | Write PHP peak | Read seconds | Read PHP peak | ZIP bytes |
|---|---:|---:|---:|---:|---:|
| 10,000 | 0.674 | 10 MiB | 5.509 | 30 MiB | 283,627 |
| 100,000 | 4.534 | 10 MiB | 173.413 | 104 MiB | 2,810,235 |

These measure file IO/parsing, not complete catalogue import throughput.
PhpSpreadsheet chunk filtering retains formatting and formula rejection but
reparses XML/shared strings for each chunk. Export XML is disk-backed; the HTTP
export service still reads the final ZIP into a response string.

The readiness regression initially measured 233 queries for 25 products. It
now requires the full page to use at most two more queries than one product,
with approved documents and frozen profiles, and verifies each score/blocker
count against standalone evaluation. This is a page query budget, not a
100,000-passport recomputation benchmark.

## Deployment dependencies

1. Review/split the current diff into the units below, preserving existing user
   work. Do not assume each dirty file belongs entirely to remediation.
2. Apply `2026_10_02_000001_add_fortnox_page_watermark` before the Fortnox service
   change and `2026_10_02_000002_freeze_dpp_draft_profiles` before DPP snapshot
   writes. Both migrations were tested only in disposable schemas.
3. Deploy code and restart long-lived workers together. Keep `webhooks:recover`
   on the scheduler; configured worker timeout must remain below queue retry
   visibility. Validate destination egress policy and actual queue configuration
   in staging.
4. Run staging acceptance with real queue workers, realistic files and tenant
   fixtures before increasing commercial catalogue limits.

Review units, ordered by dependency: publication cleanup/security → exact
document evidence/idempotency → approval transaction → import authorization and
worker boundaries → Fortnox page/checkpoint/conflict boundaries → DPP execution
and frozen profiles → webhook producers/transport/outbox recovery → readiness,
XLSX, capacity and telemetry. Shared files require splitting by logical hunks;
no PRs or commits were created automatically.

## Remaining technical debt and evidence limits

The [2026-10-03 follow-up](2026_10_03_SCALING_AND_PROVENANCE.md) supersedes the
items below concerning preview lookups/counters, execution provenance,
legacy page batching, document staging, duplicate services and ZIP response
buffering. It records updated measurements and explicitly retains remaining
acceptance limits; the original estimates below describe the earlier state.

Estimates are engineering days including tests; they are planning estimates,
not promises. No known unfixed Critical defect is claimed from this review.
Absence of a finding does not prove every route or production state safe.

| Priority | Confirmed debt or unverified risk | Cost | Dependency / acceptance |
|---|---|---:|---|
| High | Full 10k/100k catalogue import/apply throughput is unmeasured. Preview still plans all rows in one transaction and performs per-row domain lookups; source hash is reread for each chunk | 3–6 days | Import boundaries first; measure SQL/locks/job time and failure resume before redesigning preview/checkpointing |
| High | Frozen configuration does not freeze executable rule implementation; legacy drafts lack complete historical requirement snapshots | 2–4 days | DPP snapshot/evidence first; version executable rules or preserve release provenance without rewriting old evidence |
| Medium | Finite capacity uses a locking offset read per creation; safe concurrency is verified, constant-time large finite-limit imports are not | 2–3 days | Profile the real pipeline; any counter/reservation design must cover create, archive, restore, rollback and concurrent tenants |
| Medium | Legacy drafts use the live compatibility profile path and can retain profile lookup N+1; new snapshot-backed page batching is verified | 1–2 days | Define explicit legacy transition policy; never fabricate historical provenance |
| Medium | XLSX chunks repeatedly parse the archive; native XML/shared-string memory grows with file size | 2–4 days | Current parsing regression suite; optimize only with equivalent format/formula/sparse-sheet behavior and RSS measurements |
| Medium | Catalogue exports retain final ZIP bytes in the HTTP response; document staging reads source/staged/promoted files fully | 1–3 days | Streaming response/storage paths with checksum and cleanup regressions; measure largest supported document |
| Medium | Resolver p95 is unavailable locally; unsupported queue/failure backends report unknown. Production SLOs need actual timing/backend collection | 1–2 days | Deploy telemetry first, measure staging/production; avoid synthetic zero latency |
| Medium | At-least-once webhook delivery may repeat after receiver success but before local acknowledgement. Dead-letter handling needs operational ownership | 1–2 days | Stable receiver idempotency, scheduler/worker monitoring and staged crash drills; no exactly-once claim |
| Low | Duplicate snapshot/encoder services and long publication/import actions remain architectural coupling | 1–3 days | Extract only after boundary tests; remove unused duplicates by verified call graph, in separate review units |
| Low | Regulatory completeness, asset revocation/cache policy and tariff counting semantics remain product decisions | Requires product input | Current catalogue limits count draft/active products; generic ESPR coverage is not legal certification |

These items are explicitly not reported as fixed bugs or as proven production
incidents. Broad rewrites were avoided; reliability evidence precedes further
abstraction or scaling changes.
