# API token mutation boundaries — 2026-10-03

This completes the in-flight credential finding in
`2026_10_03_TRANSACTIONAL_AUTHORIZATION.md`, following the Billing follow-ups.
The patch base includes the previously accepted, uncommitted remediation.

## Confirmed failures and execution paths

`EnsureApiTokenIsValid`, `EnsureApiCompanyMembership`,
`EnsureApiCompanyIsActive` and `EnsureApiTokenAbility` checked authenticated
request state before persistence. `CompanyAuthorizer::authorize` revalidated
company permissions inside existing domain transactions, but did not revalidate
the API credential. `WebhookEndpointController::store`, `update`, `disable`
and `ImportController::storeTemplate` wrote directly without that boundary.

Saved-baseline HTTP negative controls reproduce accepted writes after token
deletion, expiry, ability removal, tenant rebinding, credential hash replacement,
membership removal and user/company suspension. Eleven of twelve initial cases
fail their intended rejection assertions; the independent role-downgrade control
passes. Both separate import-template create/update negative controls also fail.

Six saved-baseline MySQL concurrency cases fail: five changes committed on a
second connection remain invisible behind an older REPEATABLE READ snapshot,
and credential/tenant revocation can commit while an endpoint write remains
uncommitted. These tests invoke an already authenticated request through the
API middleware and controller; they deliberately omit Sanctum's last-used write,
which could otherwise incidentally serialize the credential. HTTP tests exercise
the normal bearer request path separately.

## Resulting protocol

`EnsureApiTokenAbility::handle` binds an original credential clone and required
abilities to scoped `ApiTokenMutationAuthorizer` state. The binding unwinds in
`finally`, including after denied requests. There is no encompassing request
transaction: existing import chunks and Fortnox page/refresh boundaries remain.

Within a transaction, `CompanyAuthorizer::authorize` invokes the credential guard
for the original HTTP actor carrying its personal access token. The guard uses
current locking reads in Company (exclusive) → User → membership → token
(shared) order. It checks current active states, membership, original credential
hash, token ownership/tenant, expiry and every bound ability before mutation.
Existing company permission checks still run after this credential check.

Three new webhook actions and `SaveApiImportMappingTemplate::execute` explicitly
use the same guard in their own transactions. Endpoint updates reacquire the
tenant-scoped UUID row under an exclusive lock and allow only the existing
editable fields. Templates retain name normalization, filtering, creator/update
behavior and response shape. Tenant identity remains server selected.

The documented ability contract is preserved: a webhook token with
`webhooks.manage` remains usable after a role downgrade while membership remains.
No new Owner/Admin policy substitutes for token authorization. API error codes,
request IDs and omission of webhook secrets are covered by HTTP regressions.
`docs/API.md` and OpenAPI describe the transaction boundary.

A revocation committed before authorization denies the write. Once authorized,
the transaction holds its credential/tenant locks until the outer commit;
competing token deletion/ability edits, membership deletion and user/company
suspension wait. Expiration is evaluated at authorization. It does not cancel an
already accepted transaction, and another transaction revalidates.

Previously committed queued work retains the worker's actor/tenant checks.
`ApplyImportJob::apply` loads its actor from a scalar ID without an attached HTTP
token. An added regression exposed the first guard version incorrectly applying
the originating request credential to such a worker during synchronous execution.
`authorizeIfBound` now distinguishes those fresh actors from the original HTTP
actor; the regression covers successful authorized worker mutation after token
revocation. Failed-request scope cleanup is covered separately.

## Verification and limits

The final expanded selection passes **485 tests / 1851 assertions** in 188.727
seconds, exit 0 with JUnit errors/failures zero. It includes the new MySQL
concurrency tests and all API, authorization, privacy, Fortnox and import feature
tests. This is a selected regression run, not a new full-application test run.
Pint passes all twelve PHP files in this unit; PHPStan reports zero errors.
The twelve run-start source hashes still match the final PHP sources. Earlier
HTTP, concurrency and template negative controls are retained separately.

The route inventory regression checks every protected API write declares an
ability. It complements execution-path regressions; it does not prove every
future action calls the transactional authorizer. Future API actions using the
general company authorizer must pass the original authenticated actor, retain its
personal access token and authorize inside their transaction. Explicit API-only
actions must call the credential guard directly.

Read requests retain preflight authorization. Already committed chunks and jobs
are not undone by token revocation. No real staging lock-wait or throughput
measurement is claimed. Shared authorization currently repeats a bounded set of
company/user/membership reads for API writes; the remaining Company mutex and
finite-capacity admission cost require the separate staging measurement/design
stage described in the transactional authorization and capacity reports.

Tests use PHP 8.4.26 and MySQL 8.0.46 on the owned disposable schema
`nordipass_api_boundary_20261003_testing` with isolated storage, fake queues/HTTP
and stray-request prevention. No migration was added, normal database or
production configuration changed, or real provider/webhook request made.
No commit, push, PR or deployment was performed.
After the test and analysis processes finished, the exact disposable schema and
verified owned `storage/framework/audit-api-boundary-20261003` directory were
removed. Cleanup records the exact targets; evidence and patch snapshots remain.

Evidence is retained in the continuation's `api-token-boundaries` directory:
baseline snapshots, negative-control logs/XML, worker regression, complete-run
JUnit/log/exit code, source hashes, Pint/PHPStan results and the independently
materialized `22-api-token-mutation-boundaries.patch`. This is one coordinated
review unit for the guard, its callers, regressions and contract documentation.
