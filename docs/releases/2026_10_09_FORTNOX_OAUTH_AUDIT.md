# Fortnox OAuth and subscription audit — 2026-10-09

The application had no effective external subscription boundary and could accept the wrong Fortnox customer. A separate defect allowed the fake provider to start real external OAuth when a Client ID was configured. Both defects are corrected in the working tree. The cause of the paid Integration license addition on October 4 remains **unproven**: application activity is recoverable, but Fortnox's license-order event, actor and activation mechanism are unavailable.

The existing staging connection to API tenant **1854146** passed an authenticated read. No new external authorization, token refresh, purchase, production deployment or production connection change was performed by this audit. New OAuth startup on the installed staging release was temporarily disabled. The full source patch is **uncommitted and not deployed**.

## Evidence and scope

Source baseline: `3e6ae56ba20585b9fd17a82e1b94967e39467f02`. Reviewed the web connect/callback/disconnect flow, provider binding, real/fake providers, state persistence, connection storage, token renewal, sync/reconciliation entry points, tenant authorization, API error handling and regression coverage. Existing tests cover permission/tenant isolation, expiry, state replay, encrypted token storage and synchronization boundaries.

Local application data and staging metadata were inspected with read-only database transactions. Only non-secret identifiers, dates, scopes and selected token metadata were exported. Tokens, credentials, OAuth codes/states/hashes, sessions and headers are excluded. Local logs were streamed and classified without printing raw lines. The evidence is preserved under [evidence/2026_10_09_fortnox_oauth_audit](evidence/2026_10_09_fortnox_oauth_audit/manifest.json); the manifest hashes each JSON file.

The Fortnox correspondence below was supplied by the user; this audit did not retrieve Fortnox's billing ledger or independently verify the credit. The relevant known server deployment is staging, installed October 7. Production application databases/logs and Fortnox's private event history were not inspected. No Support message was sent.

## Confirmed sequence

User-supplied Fortnox Support history:

| Date | Confirmed correspondence |
| --- | --- |
| September 3 | Support identified an erroneous NordiPass connection to subscription **1853390**, instead of test subscription **1854146**. |
| September 7 | Support disabled the mistaken Integration license. |
| October 4 | License was added again, according to Support's October 9 reply. Exact time, actor and mechanism were not provided. |
| October 9 | Support disabled it again and credited the invoice. |

Tina's supplied wording: “Vi kan se att ni la till integrationslicensen därefter på abonnemanget igen den 4 oktober.” This identifies another license addition; it does not identify OAuth, a token refresh, a particular button or a particular actor as its cause.

The local connection belongs to NordiPass company `2`, UUID `07749588-5dba-4517-8348-5305f06fb552`. Database timestamps below are UTC; Stockholm was UTC+02 on October 4. The Stockholm calendar-day search included October 3, 22:00 UTC through October 4, 22:00 UTC.

| UTC | Stockholm | Local evidence |
| --- | --- | --- |
| Oct 3 22:43:20 / 22:43:33 | Oct 4 00:43:20 / 00:43:33 | Two connect POSTs returned 302. Two state records remained unconsumed and subsequently expired. |
| Oct 3 23:01:18 | Oct 4 01:01:18 | Connect POST returned 302; company `2`, user `1` state created. |
| Oct 3 23:01:28–29 | Oct 4 01:01:28–29 | State consumed; callback returned 302; immutable `fortnox.connection.connected` event at 23:01:29. |
| Oct 3 23:01:32 | Oct 4 01:01:32 | Sync POST returned 302. A redirect alone does not establish successful sync. |
| Oct 3 23:01:42 | Oct 4 01:01:42 | Disconnect POST returned 302 and immutable disconnect event recorded. |
| Oct 3 23:01:43 | Oct 4 01:01:43 | Another connect POST returned 302; another state created. |
| Oct 3 23:01:47 | Oct 4 01:01:47 | Second state consumed, callback returned 302, second immutable connected event recorded for the same connection. |
| Oct 3 23:01:50 | Oct 4 01:01:50 | Sync returned 500. Sanitized stack classification identifies a database error in article application/product creation. It contains no known subscription ID or license-order evidence. |
| Oct 4 14:04:41–42 | Oct 4 16:04:41–42 | Current encrypted connection was updated at 14:04:41; retained JWT was issued at 14:04:42 for tenant **1854146**. |

The application therefore did process two completed connection callbacks with an intervening disconnect on October 4 in Stockholm. All historical connected events stored `external_tenant_id = null`. They also did not record the HTTP/fake provider choice or integration Client ID used at that time. The persisted events prove application connection saves; they do not independently prove two real Fortnox token exchanges or the selected external subscription. `consumed_at` by itself only proves state consumption, which occurs before token exchange.

The retained token belongs to **1854146**, but was issued later than the connection callbacks. It cannot retrospectively identify the account selected at 01:01. The [October 4 release gates](2026_10_04_RELEASE_GATES.md) separately record a real one-article read and exactly one normal expired-token refresh, with encrypted local credentials rotated. The original referenced `fortnox-real-read-acceptance.json` was not located; the prior report and current token metadata support this interpretation, without proving a license-order link.

Nine local log files were inspected. Searching both October 3 and 4 found 734 lines in dated contexts, 54 Fortnox/OAuth references and 23 corresponding classified log entries. HTTP route/status telemetry corroborates the sequence above. No known subscription ID or license-addition event appeared in those classified entries. Missing log evidence cannot prove that no external order occurred.

Git history for provider/configuration files contains the September 20 baseline and October 9 aggregate release commit, with no October 4 committed configuration snapshot. `.env` is unversioned. Historical credentials/provider changes and Fortnox browser account selection cannot be reconstructed from these commits.

## Authorization flow and account selection

1. The authenticated company owner/admin starts a POST under the current company context. The action checks company permission and entitlement; it never derives tenancy from submitted `company_id` or `tenant_id`.
2. The application generates random state, stores only its hash with company, initiating user and expiry, and redirects to Fortnox. Existing URL parameters are `client_id`, `redirect_uri`, `response_type=code`, `scope`, `state` and `access_type=offline`.
3. Fortnox performs login/account selection and permission approval. The documented authorization parameters do not provide a subscription pin; `account_type=service` selects service-account authorization rather than test/production. No undocumented selector was invented in this fix. [Fortnox authorization reference](https://www.fortnox.se/developer/authorization/get-authorization-code).
4. On callback, the action checks the initiating actor, current company, expiry and single use under a row lock. It consumes state, exchanges the code over HTTPS and then locks the scoped connection before saving encrypted credentials and an immutable connected audit event.
5. Subsequent sync/reconciliation uses stored access credentials, renewing an expired token through a separate refresh-token POST. Refresh does not generate a connected audit event. A changed `updated_at` alone is insufficient evidence of reauthorization.
6. NordiPass Disconnect clears local credentials and records a local revocation. Its implementation does not call a Fortnox license cancellation or token-revocation endpoint. It must not be interpreted as cancellation of a paid Fortnox license.

**Client ID and API tenant are separate identities.** The Client ID identifies the registered integration; its Developer Portal owner is not established by the API customer's JWT. A single integration can be tested against a separate customer/test environment. Portal ownership on a developer subscription does not itself prove that the API connection targets that subscription. The UI/account selected during authorization and the resulting `tenantId` must be distinguished. [Fortnox Developer Portal guide](https://support.fortnox.se/kom-igang/integrationer/kom-igang-med-utvecklarportalen).

Fortnox documents extracting `tenantId` from its access-token payload, with `DatabaseNumber` as an alternative requiring suitable company-information access. The implemented parser only reads tokens returned by the authenticated provider backchannel or encrypted connection storage. It is **not JWT signature verification** and does not accept a request-supplied identity. No additional scopes or company-information requests were introduced. [Fortnox token identity guidance](https://www.fortnox.se/developer/blog/say-goodbye-to-refresh-tokens-).

## License activation: established mechanism versus unknown incident cause

Fortnox's customer guide describes an active Integration license as a prerequisite, account login and permission approval, followed by automatic activation of the **connection**. That is not evidence that token exchange automatically orders the paid license. [Fortnox integration activation guide](https://support.fortnox.se/kom-igang/integrationer/kom-igang-med-integrationer).

Its FAQ documents ordering a missing Integration license through additional orders/user administration; purchasable marketplace integrations have a separate purchase path. These are documented general mechanisms, not a finding about this October 4 event. [Fortnox developer FAQ](https://www.fortnox.se/developer/faq).

The reviewed NordiPass real provider sends token grant POSTs and article GETs. No Fortnox license purchase/order/reactivation endpoint is implemented in the reviewed integration. There is no evidence that the October 4 refresh purchased a license. External ordering during account selection, marketplace activity, user administration, another actor or Fortnox-side processing remain possible categories for Support to investigate; none is assigned as the root cause.

**Incident root cause remains open.** Proven contributing application defects were absence of external-account validation and the fake provider's real OAuth redirect. Their existence shows a repeatable wrong-account risk; it does not establish that either caused this particular license addition. Correlation with October 4 application callbacks must be checked against Fortnox's actual license event timestamp and mechanism.

## Source corrections

| Finding | Correction / status |
| --- | --- |
| Real tokens always persisted a null tenant; production/other accounts could be accepted in nonproduction. | New `FortnoxSubscriptionGuard` extracts the server token identity. Outside actual `production`, **1853390 is hard-blocked**, and unknown or unexpected accounts fail closed. Default permitted API tenant is **1854146**. |
| A callback-only guard would act after external consent. | New external OAuth is **disabled by default** in every nonproduction environment. Both authorization URL generation and code exchange enforce the gate. Enabling requires explicit operator approval and a pinned integration Client ID approved for that test setup. |
| Fake provider could redirect to real Fortnox when credentials existed. | Fake authorization always returns to the configured local callback with a simulated code. It never starts real external OAuth. |
| Stored production/legacy credentials could be overwritten by reconnect or used for sync/refresh. | Existing record and stored token identity checked before reconnect, again during callback persistence, and before reads/renewal. Blocked connection rows remain unchanged, including status/failure fields. Revoked known-test records can reconnect; known-production records cannot. |
| Wrong tenant could arrive in refresh response. | Verified tenant context required before refresh; approved integration Client ID checked; returned token identity validated before credential persistence. Fresh permitted test tokens can still be read while new OAuth is disabled. |
| State stored initiating user but callback did not match it. | Callback state query now binds the initiating actor as well as company/hash, preserving expiry/single-use locking. |
| UI did not show recorded external account or protected authorization availability. | Connection view shows recorded Fortnox account, hides blocked connect/reconnect forms and displays an explanatory notice. Direct POST receives a friendly error. |

Configuration additions:

```dotenv
FORTNOX_NONPRODUCTION_OAUTH_ENABLED=false
FORTNOX_NONPRODUCTION_TENANT_ID=1854146
FORTNOX_NONPRODUCTION_CLIENT_ID=
```

The last value is an operator-approved integration Client ID, not a subscription number. It may be owned by a different developer subscription from the API test customer. Do not copy an unverified Client ID and interpret equality as proof of Portal ownership. Actual production is excluded from the nonproduction subscription restriction; no live production behavior was exercised or changed. API v1 envelopes/error codes are unchanged; provider failures retain the existing `fortnox_provider_error` response.

## Actual staging acceptance and containment

Installed release: `staging-source-20261007-3b3dd0fb5f00`, under `/home/codecraf/nordipass-staging/current`. Company `4`, connection UUID `eecd1f38-4e70-4465-9473-132dadf668aa`. Existing connected events on October 7 at 09:38:28 UTC (`article`) and 10:30:04 UTC (`article,price`); recorded external identity remains null in that old release. No October 4 state rows were found on staging.

At **12:54:50 UTC / 14:54:50 Stockholm**, the existing fresh token was identified as API tenant **1854146**. One native GET returned **100 articles** and indicated another page. There was no token POST, refresh, authorization or import/apply. Raw connection attributes compared identical before/after this operation. This proves working authenticated test API access; it does not prove a fresh authorization flow, exhaustive pagination, Portal ownership or billing status. [Live read evidence](evidence/2026_10_09_fortnox_oauth_audit/live-test-read.json).

At **13:10:44 UTC / 15:10:44 Stockholm**, after confirming staging environment, exact shared `.env` path and absence of live unused OAuth states, only `FORTNOX_AUTHORIZE_URL` was set to empty and staging configuration cache rebuilt. Pure authorization-URL generation then failed closed; no state or external request was generated. Connection rows compared unchanged during this operation. Token URL, API URL and existing credentials were preserved. Config/cache files containing credentials remain private. [Containment evidence](evidence/2026_10_09_fortnox_oauth_audit/staging-oauth-pause.json).

This is **temporary startup containment on the old release**, not deployment of the full account guard. Old connect UI may still offer a button and return a configuration error when used. It does not change existing automatic test-token renewal. A later read-only snapshot at 13:18:13 UTC observed a token issued at 13:00:05 UTC for **1854146** and matching connection update; that intervening rotation was not performed by the audit's GET or containment operation. [Staging snapshot](evidence/2026_10_09_fortnox_oauth_audit/staging-readonly.json).

## Verification

- `composer test -- --filter=Fortnox`: **137 passed, 538 assertions**. Includes 41 new guard/boundary cases covering erroneous production/other/unknown subscriptions, fake-provider isolation, missing/mismatched approved client, allowed test exchange/read/renewal, actor-bound state, callback races and unchanged blocked connection rows. All token exchange/renewal tests use synthetic JWT fixtures and `Http::fake`.
- `composer lint -- --test`: passed. Changed PHP files formatted first.
- `composer analyse`: passed, **0 errors**.
- `git diff --check` for affected source: passed. Blade rendering and authorization notice covered by feature tests; no frontend asset changes.
- One real test-tenant GET passed as described above; zero audit-initiated real token grants or production API requests.

The Windows PHP sandbox initially reported the existing bootstrap file unreadable. The same isolated tests ran successfully with approved unsandboxed execution. Duplicate PHP extension startup warnings are pre-existing. Initial new-test failures were test-fixture hydration/order comparisons and staging-mode CSRF behavior; these were corrected, and the final suite is green. The previously completed full release suite is separate evidence and is not claimed as a full rerun of this patch. [Verification result](evidence/2026_10_09_fortnox_oauth_audit/verification.json).

## Remaining risks and proposed follow-up

1. Keep new external OAuth disabled until Support identifies the billing activation mechanism and the integration/test account setup is verified. A post-token account check cannot reverse an order or consent already made in Fortnox. Even an approved integration Client ID does not force the user to choose the test customer. Consider a dedicated Fortnox user with access limited to the test customer and separation of approved integration credentials.
2. Deploy the reviewed source through the normal release boundary before claiming full staging enforcement. Preserve the temporary startup pause until an explicitly approved test authorization. Before enabling, verify `APP_ENV`, permitted tenant, approved Client ID, redirect URI and Portal settings. A mislabelled nonproduction deployment with `APP_ENV=production` bypasses the environment restriction.
3. Add explicit non-secret audit events for authorization start, rejected subscription and token renewal, including provider/environment, expected/observed external tenant and request correlation. Never include tokens, hashes, codes/states, secrets or callback URLs with query credentials. Existing connected events now record token identity; historical null rows must not be rewritten as if the old account were known.
4. Implement PKCE with per-state verifier storage and S256 before requiring it in the Portal. It is absent from the current flow and is a separate authorization-hardening item, without evidence of a billing connection. [Fortnox PKCE reference](https://www.fortnox.se/developer/authorization/get-authorization-code).
5. Harden endpoint trust separately: the existing public-IP exception in `assertSafeExternalUrl` bypasses the hostname allowlist, and HTTP redirect handling should be reviewed for credential-bearing token requests. Current inspected configuration uses standard Fortnox endpoints. No link between endpoint configuration and this license incident was found; these behaviors were not changed in this patch.
6. Obtain Fortnox's October 4 license-order event and map its UTC/timezone, actor, channel, affected subscription, integration and required confirmation to the local timeline. The prepared [Swedish Support request](2026_10_09_FORTNOX_SUPPORT_REQUEST.md) requests those details; it has not been sent.

This patch is outside the already-built October 9 canonical artifact. That artifact, its source commit and existing production/staging release pointers were not replaced. Commit/push, rebuilt release packaging, source deployment and any new real authorization require their own next step; none was performed here.
