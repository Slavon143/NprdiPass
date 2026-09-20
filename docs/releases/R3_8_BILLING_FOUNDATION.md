# R3.8 — Stripe Billing and Entitlements

**Status:** ACCEPTED — code gates closed; live Stripe account verification pending environment credentials
**Date:** 2026-09-18

## Scope closed

R3.8 implements the Stripe billing foundation and local entitlement enforcement required for Public Beta:

1. Feature-gated billing UI at `/settings/billing`.
2. Stripe Checkout session creation and customer portal session creation through a provider contract.
3. Fake Stripe provider for local/testing and HTTP Stripe provider for deployed environments.
4. Stripe webhook endpoint at `/stripe/webhook` with mandatory signature verification, timestamp tolerance, rate limiting, and CSRF exclusion.
5. Webhook inbox persistence with idempotency on `stripe_event_id`; failed events persist with status and error message for observability.
6. Local `company_subscriptions` projection with plan entitlements, status, current period, cancellation, and grace-period fields.
7. Daily `billing:reconcile` command scheduled at 01:30 to compare local projections against Stripe.
8. Entitlement override storage for operations-managed exceptions.
9. Server-side entitlement enforcement in the action layer for product creation limits, production imports, Fortnox connection, and public Passport publication.
10. Billing configuration and rate limits documented in `.env.example`.

## Subscription state model

The selected plan and the Stripe subscription status are kept separate and never conflated:

| Field | Meaning | Values |
|-------|---------|--------|
| `plan_key` (selected plan) | The plan chosen at checkout | `pilot`, `growth` |
| `status` (billing status) | Local projection of the Stripe subscription | `pending`, `incomplete`, `trialing`, `active`, `past_due`, `unpaid`, `canceled` |

State transitions driven by webhooks:

1. `checkout.session.completed` creates or updates the projection with `status = pending` and records the Stripe customer, plan, and (when available) subscription id. This event is **not** the source of truth for subscription state.
2. `customer.subscription.created` / `customer.subscription.updated` confirm the real status (`trialing`/`active`/…) and the period/trial dates.
3. `customer.subscription.deleted` marks the projection `canceled`.
4. A late `checkout.session.completed` never downgrades a confirmed (`trialing`/`active`/`past_due`) projection.
5. Webhook inbox delivery is idempotent per `stripe_event_id`; the tenant is resolved by the stable `metadata.company_uuid` (fallback `client_reference_id`), never by email.

Entitlement rules:

- Commercial entitlements are granted only when `status` is `trialing` or `active` (`past_due` within the grace window).
- `pending`, `incomplete`, `unpaid`, and `canceled` grant no entitlements.
- The default-plan entitlement fallback exists only for the fake provider (local/testing). With the Stripe provider, a company without a confirmed subscription has no commercial entitlements.
- The billing UI disables checkout while a subscription is pending (to avoid duplicate Checkout Sessions) and shows `Current plan` for `trialing`/`active`/`past_due`.

## Stripe environment

Production/staging must configure:

- `FEATURE_BILLING=true`
- `BILLING_PROVIDER=stripe`
- `STRIPE_SECRET`
- `STRIPE_WEBHOOK_SECRET`
- `STRIPE_PRICE_PILOT`
- `STRIPE_PRICE_GROWTH`
- webhook delivery to `POST {APP_URL}/stripe/webhook` with at least `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, and `customer.subscription.deleted`

Local and testing may keep the fake provider.

## Stage gates

| Gate | Evidence |
|------|----------|
| Stripe signature verification | `StripeWebhookVerifier`; invalid-signature feature test |
| Webhook inbox idempotency | `stripe_webhook_events.stripe_event_id` unique key; duplicate event test |
| Webhook failure observability | failed events persist with `status = failed` and error message instead of rolling back |
| Subscription projection | `BillingProjectionService`; webhook and reconciliation tests |
| Pending state flow | `checkout.session.completed` → `pending` → `customer.subscription.*` → confirmed; no-downgrade test |
| Server-side entitlements | `EntitlementService`; action-layer tests for product limits and capabilities |
| Entitlement/status separation | entitlements only for `trialing`/`active` (+ grace `past_due`); no fallback with the Stripe provider |
| Duplicate checkout protection | `StartBillingCheckoutAction` rejects checkout while pending or for the already subscribed plan |
| Reconciliation drift control | `billing:reconcile` command and scheduler entry |
| Permission/entitlement separation | Billing manage remains company permission; feature access is checked through entitlements |

## Validation commands

```bash
php artisan migrate
php artisan test tests\Feature\Billing\BillingFlowTest.php
php artisan route:list --path=billing
php artisan route:list --path=stripe
php artisan schedule:list
vendor\bin\pint --test app\Console\Commands\BillingReconcileCommand.php app\Actions\Billing\ProcessStripeWebhookAction.php app\Actions\Billing\StartBillingCheckoutAction.php app\Actions\Catalog\Products\CreateProductAction.php app\Actions\Catalog\Products\ProductAction.php app\Actions\Fortnox\ConnectFortnoxAction.php app\Actions\Imports\CreateImportJobAction.php app\Actions\Passports\PublishProductPassport.php app\Enums\Billing\BillingSubscriptionStatus.php app\Models\Billing\CompanySubscription.php app\Services\Billing\BillingProjectionService.php app\Services\Billing\EntitlementService.php app\Services\Billing\HttpStripeBillingProvider.php bootstrap\app.php config\billing.php database\migrations\2026_09_10_000001_create_billing_foundation_tables.php resources\views\layouts\partials\sidebar-nav.blade.php routes\console.php routes\web.php tests\Feature\Billing\BillingFlowTest.php
```

## Remaining environment-owned acceptance

Live Stripe E2E requires valid Stripe sandbox credentials and price IDs. Once supplied, verify:

1. Checkout creates a subscription for a test customer.
2. Stripe sends signed `checkout.session.completed` and `customer.subscription.*` webhooks to the application.
3. Portal opens for the projected customer.
4. `billing:reconcile` can retrieve the subscription from Stripe.
