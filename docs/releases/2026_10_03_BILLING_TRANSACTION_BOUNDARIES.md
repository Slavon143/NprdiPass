# Billing transaction and provider boundaries — 2026-10-03

This continuation implements the Billing follow-up from
[transactional authorization](2026_10_03_TRANSACTIONAL_AUTHORIZATION.md).
It depends on unit 14's Company-first current-read authorization protocol.
It preserves the existing role matrix, plans, entitlement calculations and
signed-webhook entry point. No migration, Stripe SDK/API-version upgrade,
commit, push, PR or deployment is part of this change.

## Confirmed defects and changes

| Boundary | Evidence and resulting behavior |
| --- | --- |
| Checkout start/cancel and portal | `StartBillingCheckoutAction::execute`, `CancelPendingBillingCheckoutAction::execute` and `CreateBillingPortalSessionAction::execute` previously relied on preflight Gate. A committed membership downgrade between that check and the operation, or behind an older repeatable-read snapshot, did not prevent it. Each now invokes transactional `CompanyAuthorizer::authorize` before subscription locks and provider calls. |
| Current subscription state | Start and projection used ordinary reads. A confirmed Active subscription or an extended grace period committed behind an older snapshot could be overwritten. Subscription reads now use `FOR UPDATE`; `BillingProjectionService::upsert` acquires Company then subscription and saves that current model. Webhook branches share this Company mutex. |
| Checkout failure compensation | The former catch branch deleted or restored the claimed row unconditionally. Tests interleave a newer Active projection or replacement Pending claim before compensation. Compensation now requires the original row, matching generated claim UUID, Pending status and no accepted subscription identifier. |
| Uncertain remote creation | A retryable HTTP failure could delete the local claim even though Stripe might have created a usable session. The claim UUID and `creation_uncertain=true` are stored before POST; the provider sends a stable `Idempotency-Key`. A success stores the session ID and clears the uncertainty flag. An ambiguous failure retains the claim and prevents another checkout or local cancellation. There is no automatic POST replay. |
| Cancellation versus remote capability | Local cancellation previously released Pending without closing the remote session. Cancellation now confirms expiration of the recorded session before restoring local state. On an expiration POST returning 400, the HTTP provider retrieves the same session and accepts only its exact ID with status `expired`. A completed session or an unavailable provider retains Pending and creates no cancellation audit. |
| Delayed expiration events | Expiry previously identified only Company and Pending. It could release another attempt or an accepted Pending subscription. It now requires the exact recorded session ID, no accepted subscription ID and no completion marker. A matching event restores the pre-checkout snapshot, including trial dates and entitlements. |
| Historical unidentified claims | A missing session ID is not evidence that a checkout has expired. Such events are ignored and production cancellation requires operator reconciliation. The existing known-session legacy canceled-customer fallback remains covered. Fake local simulation can retain its no-session cancellation behavior. |
| Renewal after a completed checkout | The inherited `checkout_session_id` completion marker incorrectly protected a later checkout from its own matching expiry. Start now removes it from the new claim while preserving it in `previous_state`. The renewal regression verifies restored canceled status, customer/subscription identifiers, plan and original metadata. |

`StripeBillingProvider` gains claimed creation and expiration methods; both
concrete implementations are updated. Fake claimed creation delegates to the
existing method so existing provider spies still intercept it. The cancellation
controller handles provider failure through its existing billing error response.
The webhook payload delivered to tenant endpoints remains status and plan only.

## Protocol and operational limits

User-initiated operations use Company X → active User S → membership S →
subscription locks, and hold authorization through the provider call. The
webhook inbox locks its event before Company and subscription; projection and
compensation do not lock inbox rows. Provider projections have no user actor
and do not apply user role authorization.

The HTTP checkout controller invokes start as a root operation: the claim
transaction commits before remote creation starts. A separate-connection
regression checks this committed state, including the UUID and uncertainty flag.
Feature tests wrapped by RefreshDatabase alone do not prove durable commit.
A future caller must not wrap checkout start in an outer transaction: nested
savepoints cannot independently commit the claim before external I/O.

External sessions cannot be rolled back by SQL. Portal authorization is held
during creation; an already issued portal URL is an external capability and is
not revoked by a later role downgrade. Checkout/portal calls can hold the tenant
mutex for the configured 30-second HTTP timeout; expiration followed by
verification can hold it for approximately two such timeouts. No throughput
claim is made for these locks. Staging contention measurement remains required.

Unknown checkout outcomes deliberately stay Pending. The existing
`ReconcileBillingProjectionAction` selects only rows with a subscription ID;
it does not recover unidentified checkout sessions. An operator must verify
Stripe state before repairing these rows. Historical identifiers are not guessed
or backfilled. A recovery tool is a separate follow-up.

The provider still lists one page of up to 100 subscriptions. Exhaustive
pagination, ordering of different subscription events, and remote ownership
validation remain separate audit candidates; this patch does not certify the
entire Stripe integration.

Stripe semantics were checked against primary documentation:
[expire a Checkout Session](https://docs.stripe.com/api/checkout/sessions/expire),
[idempotent requests](https://docs.stripe.com/api/idempotent_requests), and
[customer portal integration](https://docs.stripe.com/customer-management/integrate-customer-portal).
No real provider call or account configuration change was made.

## Verification and review package

The broad run passes **123 tests / 488 assertions**, 342.132 seconds, exit 0,
JUnit errors 0 / failures 0. It includes the 16 real-connection concurrency
cases, all Billing feature tests, authorization and privacy. Its 13 PHP source
files match the retained manifest. This run precedes the final renewal-marker
adjustment and is not claimed as a broad run of that last adjustment.

The additional renewal test fails on the saved pre-adjustment code for the
intended inherited-marker assertion (one test / one assertion). After the
two-line production adjustment, **all Billing feature tests pass: 49 tests /
217 assertions**, 22.290 seconds, exit 0, JUnit errors 0 / failures 0. The final
13-file source manifest is retained and checked. Pint passes all 13 edited PHP
files; PHPStan passes with zero errors after the final adjustment. No analysis
ignores or baseline suppressions were added. The whole application suite was
not rerun in this Billing stage; the prior full-suite evidence belongs to its
documented earlier source state.

The retained negative and intermediate evidence includes:

- Thirteen intended baseline failures (67 assertions) for stale authorization,
  stale subscription reads, destructive compensation, unsafe retry and portal
  revocation during the provider call.
- Two projection and two expiry intended failures against saved old classes
  (10 tests, six passing controls, 36 assertions overall).
- Two intended legacy failures (two assertions): unrelated expiry deletes the
  row and cancellation does not require reconciliation.
- An initial fixed run with six test-comparison errors: unrefreshed model
  attributes and database JSON ordering. Fixtures were refreshed and comparisons
  narrowed to meaningful cast state. These are not reported as production bugs.
- An intermediate broad run interrupted for the missing-session legacy fix.
  Incomplete runs are not pass evidence.

Tests use PHP 8.4.26, MySQL 8.0.46, an owned disposable `_testing` schema,
fake provider/HTTP, fake queues and `Http::preventStrayRequests`.
The normal application database and production are untouched.

Evidence directory:
`C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/next-pass/staging-acceptance-20261003/billing-boundaries`.

The review patch is prepared against the saved dirty continuation baseline,
not repository HEAD, so earlier accepted work is preserved. The Billing
contract/provider/actions form one coordinated protocol change. In-flight API
credential revocation is the next separate stage; staging acceptance, operator
checkout recovery and lock-wait measurement remain outstanding.

`20-billing-transaction-boundaries.patch` is checked and applied to an independent
normalized baseline, and all 15 materialized files are compared with the workspace.
Patch verification and source digests are retained alongside the test logs.
After test workers finished, the exact disposable schema
`nordipass_billing_boundary_20261003_testing` and the verified owned
`storage/framework/audit-billing-boundary-20261003` directory were removed.
Cleanup logs record those targets; evidence, baselines and patch snapshots remain.
