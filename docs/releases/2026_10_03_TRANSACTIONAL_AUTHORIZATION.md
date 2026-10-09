# Transactional authorization, review units 14–19

Confirmed on local PHP 8.4.26 and MySQL 8.0.46. The saved baseline contains the
earlier uncommitted remediation; these patches do not represent a clean HEAD diff.

## Confirmed defects and evidence

1. **High — authorization from an older REPEATABLE READ snapshot.**
   `CompanyAuthorizer::authorize` delegated to `allows`, which checked the passed
   User status and performed an ordinary membership SELECT through
   `CurrentMembership::get`. Another connection could commit a role downgrade,
   membership deletion, Company suspension, User suspension or User soft deletion
   after the transaction established its read view. `CreateProductAction::execute`
   then created the aggregate and its audit despite that committed revocation.
   The five two-connection cases fail on the baseline specifically because no
   AuthorizationException is thrown. Savepoints do not replace the outer read view.
2. **High — secondary Owner checks read the same stale role.**
   `ChangeCompanyMemberRole::execute` performed its permission check and subsequently
   used an ordinary membership read for the Owner-only promotion check. An actor
   already demoted to Admin could promote another Owner. The sixth baseline case
   reproduces this with a second connection. `RemoveCompanyMember` and invitation
   role authorization used the same getter; their authoritative role reads now use
   `getForAuthorization`. `CompanyInvitationAuthorizer::authorizeManage` also reads
   the current tenant-scoped invitation, rather than delegating to its UI predicate.
   A separate negative control changes the invited role to Owner behind an Admin's
   old snapshot: the baseline incorrectly grants management.
3. **High — authorization ended before the mutation transaction began.**
   `CreateProductWithDefaultVariantAction::execute`, both company API token actions,
   `UpdateCompany::execute`, bulk categories and document review transitions had
   outside-only authorization. A TransactionBeginning hook commits a downgrade
   through another connection after preflight and before the transaction body.
   Seven representative baselines still mutate: legacy create, token creation,
   token revocation, company update, bulk category archive, document review submit
   and Fortnox disconnect. Their regressions now require denial with unchanged
   resource counts/statuses and no new audit. The same missing boundary was fixed
   in category/attribute deletion, bulk attribute/product archive and import
   create/preview operations. These additional paths have suite coverage but are
   not separately claimed as seven new reproductions.
4. **High — provider I/O separated Fortnox preflight from page writes.**
   `FortnoxSyncService::execute` did not check FortnoxManage in its page or final
   watermark transactions. A fake provider downgrades Owner to Editor while
   returning either an empty page or an article. Both baselines complete without
   authorization failure; the article case also creates a product because Editor
   still has catalogue-create permission. The fix checks FortnoxManage before
   each page, final watermark and token-refresh transaction. Reconciliation passes
   its actor and checks this permission inside each mapping repair/archive
   transaction. Connection, mapping and product locks follow the Company guard.
5. **High — resend used an old pending invitation.**
   `ResendCompanyInvitation::execute` read pending state before entering its nested
   invite transaction. Cancellation committed behind an older read view was missed
   and a new invitation was created. A second-connection negative control reproduces
   that missing InvitationCannotBeResent exception. Resend now authorizes and reads
   the scoped invitation with FOR UPDATE in one transaction before delegation.
6. **Lock-order risk, identified from code.** Import apply/start/cancel,
   publication approval and Fortnox conflict/page operations previously acquired
   resource locks before nested catalogue actions acquired Company locks. Adding a
   Company mutex to authorization without moving those entry guards would leave
   Company→resource and resource→Company orders. Guards now run before those locks.
   This is a code-proven inconsistent order, not a claim that a production deadlock
   was observed in this audit.
7. **High — account erasure missed current ownership and tenant serialization.**
   `AccountErasureService::erase` discovered only owned companies with an ordinary
   snapshot read and deleted memberships before updating User. In a controlled
   ownership transfer committed behind an Editor's old snapshot, that Editor became
   the sole Owner, but erasure skipped its Owner check and removed the last Owner.
   Another control adds a sole-Owner membership in a new tenant behind the snapshot;
   erasure also missed that tenant and removed its ownership. A third control holds
   Company FOR UPDATE on another connection: the baseline erases the user anyway.
   All three fail for the intended missing denial/serialization. The baseline also
   has membership→User order, opposite to the new User→membership guard, so it could
   deadlock with guarded operations. This deadlock is a code-level risk rather than
   an observed production incident.

   Erasure now discovers all company IDs, locks Companies in ascending ID order,
   locks the current User, and reads memberships FOR UPDATE. Newly discovered
   tenants cause a validation response before tokens, personal rows or memberships
   are deleted. Current Owner roles are checked while the Company mutex protects
   other owner mutations. Company role/remove and invitation-cancel guards also
   run before target-resource locks. The first full verification was deliberately
   interrupted with Ctrl-C to integrate this required lock-order fix; its incomplete
   log is not pass evidence.
8. **High — new-company creation used a revoked account.**
   `CreateCompanyForOwner::execute` accepted the passed User without a current
   status/soft-deletion check. Both registration and `CreateCompanyController::store`
   call it. A request begun before erasure could resume its FK membership insert
   after erasure released User, creating a new tenant for the deleted account and
   copying its cached email into billing_email. Two controls suspend or soft-delete
   User behind an older read view and require zero Company/membership/subscription
   rows. The fix reads an active non-deleted User with a shared lock before any new
   tenant rows and uses its current email. This new-tenant action does not require
   an existing CurrentCompany or invent tenant authorization for registration.
   The first controls were invalid because they omitted required country_code and
   the baseline hit MySQL 1048. Those diagnostic logs are retained and are not
   authorization-failure evidence. Valid controls supply SE: both baseline actions
   finish with no authorization exception. Their eight assertions fail on the null
   denial, rather than a database constraint. A second incomplete full attempt was
   stopped before integrating this fix; only the final complete run is pass evidence.
9. **High — passport language updates bypassed the mutation guard and reused an old draft.**
   `UpdatePassportLanguagesAction::authorize` called `$actor->can`, so Gate still
   used the ordinary `allows` read even inside its transaction. Three controls
   commit a role downgrade, Company suspension or User suspension behind the outer
   read view: the saved method accepts all three rather than throwing authorization.
   A fourth control commits a draft edit and revision bump while holding Company
   and passport locks on the second connection. The method subsequently reads
   `currentDraftVersion` through an ordinary relationship: it writes revision 2
   instead of the required 3. The regression also requires preservation of the
   committed translated public_name. The fix invokes transactional authorize,
   scopes the locked passport by company/product, and reads the draft FOR UPDATE.
   Language validation and published-snapshot behavior remain the same.

## Implemented boundary

`CompanyAuthorizer::authorize` inside a transaction binds the selected tenant
before locking, then takes Company FOR UPDATE, active non-deleted User shared lock
and current membership shared lock. It evaluates the existing permission matrix
against those current records. The locks last until the outer transaction ends.
Outside transactions, preflight behavior remains; `allows` and policy/UI predicates
remain ordinary reads. Platform roles still do not create tenant membership.

The exclusive Company mutex avoids shared-to-exclusive upgrades in nested capacity
and company/member actions. It deliberately serializes guarded writes in a tenant;
it is a security/integrity tradeoff, not a throughput optimization. Long previews
and token refresh I/O inside their existing transactions can hold it longer.
Each successful transactional authorization reads Company, User and membership,
instead of only the membership lookup in the earlier authorizer. End-to-end
throughput at 10k/100k products with this guard has not been measured.

`UpdateCompany` also loads the locked current Company before computing field changes
and audit old values. Import-upload cleanup rethrows authorization failures instead
of letting the idempotency fallback swallow them. Published snapshot and readiness
semantics, API error envelopes, tenant filters and token abilities were not redesigned.

## Verification

The original six baseline cases fail with the intended missing-authorization
exception (30 assertions). Ten additional baseline cases fail for the same missing
authorization exception (37 assertions); the cancelled-invitation control fails
for its missing InvitationCannotBeResent exception (4 assertions). All controls
load saved classes through a separate Composer bootstrap; unsafe classes are never
applied to the workspace.

The six current-read cases and existing authorization suite pass: 60 tests /
169 assertions. The broader initial run has 68 tests, 61 passes, seven fixture
errors and 346 total assertions: the new fixture tried to change immutable document-version
content. This was a test-authoring error, corrected by creating the document in
its intended tenant. Its log is preserved. The repaired seven boundary cases and
two Fortnox cases pass: 9 tests / 100 assertions. Resend passes: 1 test / 6 assertions.
The three erasure controls fail on the saved baseline (12 assertions); the fixed
erasure plus existing privacy and authorization tests pass: 62 tests / 171 assertions.
The two valid company-creation controls fail on the baseline (8 assertions); the
fixed guard and existing registration tests pass: 5 tests / 25 assertions.

The successful-mutation concurrency case holds an outer transaction after creating
a product. Raw Company suspension, User suspension and membership downgrade each
time out with MySQL error 1205. After commit, downgrade succeeds and the next create
is denied. It checks both current reads and lock lifetime.

The complete suite for units 14–18 passes: **2564 tests / 11304 assertions**,
1247.421 seconds, exit 0, JUnit errors 0 / failures 0. The 42 source files match
the retained full-suite source manifest. This run predates unit 19 and is not
presented as a full-suite run of that later change.

Unit 19's saved method fails all four controls for the intended reasons: three
missing AuthorizationExceptions and revision 2 instead of 3 (19 assertions).
The final typed-query implementation passes the four regressions plus all selected
localization/publication tests: **139 tests / 408 assertions**, 137.068 seconds,
exit 0. Publication checks include frozen language snapshots, prior-version
payload/checksum and public-page ETag stability after draft changes.

Pint passes all 44 edited PHP files; after the last query adjustment, its two
affected files were checked again. PHPStan passes with zero errors. An intermediate
relationship query inferred a generic Model and caused two property errors; it
was replaced with a typed ProductPassportVersion query scoped by Company and
passport. The intermediate focused run was interrupted for that adjustment and
is not pass evidence. No ignores or analysis-baseline suppressions were added.

The six patches are checked and applied sequentially to an independent normalized
baseline, then every materialized file is compared with the workspace. Final
source manifests and verification logs are retained in the evidence directory.

## Dependency order and remaining work

Billing item 2 below records the finding at this stage's completion. Its follow-up
implementation and current verification are documented in
[Billing transaction boundaries](2026_10_03_BILLING_TRANSACTION_BOUNDARIES.md).
The API credential boundary in item 3 remains a separate next stage.

1. Review **14-transactional-authorization.patch** (shared boundary, authoritative
   role/invitation checks and snapshot/lock regressions), then
   **15-mutation-authorization-boundaries.patch** (entry guards and resend), then
   **16-fortnox-page-authorization.patch** (page/refresh/reconciliation guards), and
   **17-account-erasure-transaction-boundary.patch** (privacy/Owner serialization),
   then **18-company-creation-actor-boundary.patch** (active registration/self-serve
   owner boundary), and **19-passport-language-transaction-boundary.patch**
   (authoritative passport permission and draft current read).
   These are patch artifacts for review, not opened or committed PRs.
2. **High, separate audit/fix: Gate-only billing actions.**
   `StartBillingCheckoutAction::execute` checks Gate before its production claim
   transaction; `CancelPendingBillingCheckoutAction::execute` does likewise.
   `CompanyPermissionGate::billingManage` calls the unchanged `allows` predicate.
   These do not automatically inherit the new transactional authorize behavior.
   Reproduce preflight/revocation boundaries on the production branch with a fake
   provider, then add the actor guard before mutation. The fake checkout shortcut
   must not be mistaken for coverage of the production claim transaction.
   `CreateBillingPortalSessionAction` has an external-session boundary requiring
   its own decision; no provider call can be rolled back by a local transaction.
   Estimated focused implementation/testing: 1–2 days; depends on unit 14.
3. **High candidate, not reproduced here: in-flight API credential revocation.**
   `EnsureApiTokenIsValid`, `EnsureApiTokenAbility` and
   `EnsureApiCompanyMembership` check request state before the body; webhook
   endpoint store/update/disable write directly in the controller. Audit token
   deletion/expiry and membership removal between middleware and persistence,
   including current reads and token/member lock order. Token abilities have their
   own documented contract: a role downgrade does not revoke existing tokens while
   membership remains. Do not silently add Owner/Admin-only webhook policy as a
   substitute for enforcing that contract. Estimated reproduction/design/tests:
   1–3 days; depends on current-read protocol and API error-contract tests.
4. **High performance debt:** measure complete imports, Company lock waits and
   provider refresh waits with these guards on actual staging. Finite capacity
   remains O(chunk size × catalogue size); unit 13 did not introduce a counter.
   Only after the write/authorization protocol is complete should a separate
   capacity mutex/counter be designed with all supported writers and reconciliation.
   Estimate 2–4 days for measurement/design; implementation depends on its result.
   These are rough engineering estimates, not measured effort or commitments.
5. **Medium contract mismatch, no current failure with validated HTTP input:**
   `CreateCompanyForOwner::execute` exposes a nullable countryCode default but inserts
   null into the NOT NULL companies.country_code column. Current HTTP callers require
   country_code. Align this internal signature/validation separately; do not confuse
   MySQL 1048 from invalid fixture input with effective authorization. Estimated
   narrow contract/test change: less than one day.

No application migration was added. Test migrations ran only on the owned
disposable schema `nordipass_tx_auth_20261003_testing`. No production run, real
provider/webhook call, commit, push, PR or deployment was performed.
Production/staging acceptance remains separate.
After all workers finished, the disposable schema and the verified owned
`storage/framework/audit-tx-auth-20261003` directory were removed. Evidence and
patch snapshots were retained; cleanup logs record the exact targets.

Evidence directory:
`C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/next-pass/staging-acceptance-20261003/transactional-authorization`.
Retained artifacts include full-suite-complete.xml/log/exitcode,
full-suite-source-manifest.json, passport-languages-final.xml/log/exitcode,
negative-control logs/bootstraps, formatting/static-analysis results, patches and
their materialization/source verification manifest.
