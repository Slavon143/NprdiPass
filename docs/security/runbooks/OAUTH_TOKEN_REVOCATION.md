# Runbook: OAuth Token Revocation (Fortnox / Stripe)

Status: R3.16 deliverable. Covers tenant-disconnect flows and provider-side revocation.

## Fortnox Tenant Disconnect

1. Operator or tenant admin opens `/settings/integrations/fortnox`.
2. `POST /settings/integrations/fortnox/disconnect`:
   - revokes the stored encrypted token pair,
   - marks the connection `disconnected`,
   - writes audit event `fortnox.connection.disconnected`.
3. Verify: `fortnox:sync --all-companies` skips the disconnected tenant without retry loops.

## Stripe Connection (Billing)

1. Billing access is managed via `feature.billing`; cancel the subscription in Stripe (customer portal) before removing access.
2. NordiPass stores no payment data — only subscription projection rows (`company_subscriptions`) which are business records and are not deleted by user erasure.

## Provider-Side Compromise

1. Revoke the client at the provider console (Fortnox developer portal / Stripe dashboard).
2. Rotate client credentials in the deployment environment (`FORTNOX_CLIENT_ID/SECRET`, `STRIPE_*`).
3. Force re-connect for affected tenants (new OAuth authorization; state parameter + HMAC protects the callback).
4. Record the event in the platform audit (`platform.action`) with the affected tenant UUIDs.

## Verification

- `php artisan test tests/Feature/Fortnox` — OAuth state, token encryption, and disconnect flows.
- `php artisan test tests/Feature/Billing` — webhook signature and projection behavior after disconnect.
- Confirm no revoked token is reused: connections are loaded per tenant and validated on each API call.
