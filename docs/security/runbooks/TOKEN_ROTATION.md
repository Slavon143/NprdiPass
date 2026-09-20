# Runbook: Token Rotation

Status: R3.16 deliverable. Applies to API tokens, Fortnox OAuth tokens, Stripe webhook signing secrets, and webhook endpoint secrets.

## API Token Compromise

1. Identify the token: `settings/api-tokens` in the tenant UI, or audit events `api_token.created` / `api_token.revoked`.
2. Revoke immediately: `DELETE /settings/api-tokens/{token}` (tenant admin) or via `RemoveCompanyMember`-style revocation when the member is removed.
3. Issue a replacement token and update the consumer.
4. Audit: revocation is written automatically (`api_token.revoked` with actor, abilities, and reason).

## Fortnox OAuth Token

1. `POST /settings/integrations/fortnox/disconnect` revokes the tenant connection; tokens are encrypted at rest and never returned by the API.
2. Reconnect via the Fortnox OAuth flow (state parameter + HMAC prevents forgery).
3. For a suspected provider-side compromise, revoke the client at the Fortnox developer console and re-issue client credentials; rotate `FORTNOX_CLIENT_SECRET`.

## Stripe Webhook Signing Secret

1. Rotate the endpoint signing secret in the Stripe dashboard.
2. Update `STRIPE_WEBHOOK_SECRET` in the deployment environment; deploy.
3. Verify: `billing:reconcile` and the webhook inbox continue to verify signatures (`stripe_event_id` idempotency prevents replays).

## Webhook Endpoint Secret (tenant-managed)

1. Re-create the endpoint in the tenant UI — a new random secret is generated and stored encrypted.
2. Update the receiving system with the new secret before deliveries resume.

## General Rules

- Never log or audit raw token values, hashes, or secrets (enforced by `SensitiveDataSanitizer`).
- Rotate all secrets of a compromised tenant, not just the reported one.
- Record rotation in the platform audit (`platform.action`) when performed by operators.
