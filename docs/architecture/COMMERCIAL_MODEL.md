# NordiPass commercial model v1

Accepted implementation direction: 2026-10-04. These rules implement the user's commercial proposal with explicit compatibility and operating limits. Prices are launch hypotheses, not evidence of market validation or a production SLA.

## Plans and availability

Prices are EUR, excluding VAT. Annual amounts equal ten monthly payments (16.67% discount). Enterprise is a negotiated contract; it cannot be purchased through an unconfigured self-service price.

| | Trial | Starter | Growth | Business | Enterprise |
|---|---:|---:|---:|---:|---|
| Monthly | free, 14 days | 49 | 149 | 399 | negotiated |
| Annual | — | 490 | 1490 | 3990 | negotiated |
| Managed product models | 25 | 100 | 1000 | 10000 | contract |
| Internal seats | 2 | 2 | 10 | 30 | contract |
| Storage bytes | 500000000 | 2000000000 | 20000000000 | 100000000000 | contract |
| Accepted imports / billing period | 10 | 10 | 100 | 500 | contract |
| Authenticated API requests / billing period | 1000 | disabled | 50000 | 500000 | contract |
| Fortnox connections | 1 | disabled | 1 | 1 initially | contract |
| Webhook configuration and delivery | disabled | disabled | enabled | enabled | contract |

Core catalogue, CSV/XLSX, documents, readiness, immutable publication history, QR, public snapshots and export are available on paid plans and trial. Business's 10000-model allowance is an admission limit; throughput and latency depend on measured deployment capacity.

AI extraction, supplier accounts/workflows, SSO/SAML, multi-connection Fortnox, custom domains, dedicated environments and contractual SLA are planned/negotiated capabilities. Do not render them as included, charge AI usage or grant unimplemented access. Supplier seats will have a separate authorization model before any supplier invitation is enabled. AI quotas/prices require measured processing costs.

## Product unit and lifecycle

A managed product is one tenant-owned product model explicitly enrolled in the DPP workflow. Its variants do not add slots; publication versions, languages, documents and QR scans do not add slots. Batch/item passports need a separate future commercial decision.

New manually created products are managed by default. New commercial-plan ERP/file import products enter an unmanaged catalogue pool; import does not enroll or publish them. Existing products retain their previous managed status during migration. An unmanaged product may hold source data but cannot create/evaluate a new DPP draft or publish until enrolled. Enrolling and restoring a managed archived product atomically require a slot; concurrent enrollment cannot overshoot admission.

Managed draft/active products consume slots. Archived and soft-deleted products do not. Archiving preserves immutable published snapshots and their public links, but publishing a replacement requires restoration/enrollment and admission. This permits retained historical snapshots deliberately; storage continues to count retained documents and snapshot files. No history is rewritten to regain quota.

The unmanaged pool has a separate technical limit, initially 100000 product models per company, including retained managed models. File size, import row, rate and queue limits apply independently. Unlimited database ingestion is not part of any self-service plan.

## Trial and loss of access

Trial is `plan_key=growth`, `status=trialing`, with a fixed expiration and its own entitlement set. Registration requires no Stripe payment method. Trial starts once per company; cancelling or retrying checkout cannot renew it. A purchase starts paid billing without adding another full trial. A remaining trial cannot increase quotas to paid-plan levels merely because its plan key is growth.

Expired/unpaid/cancelled accounts retain authenticated viewing, download/export, billing management, token revocation, member removal and explicit unpublication. Catalogue/DPP/document writes, invitations, imports, automated Fortnox writes and outbound domain deliveries stop at the mutation boundary. Public resolution continues to serve existing snapshots independently of current subscription entitlement. No records are automatically deleted or unpublished at expiry. Read-only prevents background writes as well as HTTP writes; a stale webhook must not extend a locally expired trial.

Paid `past_due` accounts receive a fixed seven-day grace period; retries do not renew its start. Reconciliation is authoritative for payment state. Success redirects never grant paid access. Unknown prices/statuses fail closed; account metadata alone does not prove purchase of a plan.

## Quotas, downgrade and measurement

Quota periods use the confirmed subscription period; local trial uses its original 14-day interval. Annual subscriptions receive monthly operational allowances anchored within their annual billing interval; they do not receive only one monthly allowance for the whole year. Each allowance window is half-open and UTC; duplicate operation identifiers do not consume twice.

Product warnings begin at 90%. Initial commercial v1 uses a clear hard admission limit; no surprise overage charge or undeclared 5% grace. A future timed product grace requires separate approval of its exact rules. Downgrade retains existing data and seats. Existing enrolled products may be maintained, but new enrollment/restore/invitations/uploads are refused when their respective quota would be exceeded. No automatic tenant/member/product deletion is permitted.

Internal seats include the owner and all retained memberships. Unexpired pending invitations reserve seats; resend replaces the same reservation. Acceptance rechecks the current limit under the company lock. Service/API tokens are not seats and retain their own limits.

Imports count accepted source jobs, not preview/apply chunks, retries or continuation jobs. Import source rows and bytes have separate ceilings; one import cannot hide unlimited work. API admission counts authenticated tenant requests, including admitted unsuccessful requests, excluding public passport views and invalid authentication. Monthly API quota complements burst rate limits. Rejected quota requests do not keep increasing the usage counter.

Storage accounts for retained media, document versions, publication assets and retained import/export files. Files shared by references are charged once per physical storage key. Archive does not free storage; physical deletion releases bytes only after confirmed deletion. Temporary file reservations must bound concurrent uploads and be released on definite failure; ambiguous write/commit outcomes require reconciliation rather than double charging or optimistic release.

## Existing customers and rollout

Preserve the legacy pilot and existing growth contract, price IDs, stored entitlements, overrides, trial dates and publication history. Legacy plans remain resolvable but are hidden from new checkout. Do not silently reprice or relabel a paid subscription. New commercial prices carry unambiguous plan/interval mappings; transition is explicit and confirmed by provider reconciliation.

Implement in independently reviewable units: (1) plan/trial policy and compatibility; (2) managed lifecycle/counter migration; (3) mutation/feature gates; (4) quota measurement and admission; (5) checkout/annual mapping and UI; (6) acceptance. Each schema change requires paused local writers, a private backup, isolated restoration/rehearsal and verification before resume. Never use a testing `migrate:fresh` against the ordinary database.

Local tests are not staging or real-provider acceptance. Production enablement requires configured Stripe prices, sandbox lifecycle acceptance, real Fortnox acceptance, representative supplier files, target workers/proxy tests and an operator-tested backup/deployment rollback.
