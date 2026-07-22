# ADR-R3-007 — Stripe Billing and Entitlements

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None (new capability)

---

## Context

NordiPass Public Beta requires commercial billing. Stripe is selected as the payment processor. Entitlements must be enforced server-side. Failed payments must not accidentally destroy customer data.

## Decision

### Clear Separation: Permission vs. Entitlement vs. Feature Flag

| Concept | Definition | Example |
|---------|-----------|---------|
| Permission | Can a role perform this action? | `catalog.create` |
| Entitlement | Is this capability available on the company's plan? | "Up to 100 products" |
| Feature Flag | Is this capability enabled for this rollout cohort? | `advanced_dpp_sections` |

### Stripe as Billing Source of Truth
- All subscription state comes from Stripe webhooks
- NordiPass maintains a local projection: `company_subscriptions` table
- Local projection is eventually consistent with Stripe
- Reconciliation job compares local projection to Stripe API daily

### Local Projection Table: `company_subscriptions`
| Column | Type | Source |
|--------|------|--------|
| company_id | FK | local |
| stripe_customer_id | string | Stripe |
| stripe_subscription_id | string | Stripe |
| stripe_price_id | string | Stripe |
| plan_code | string | local mapping |
| status | enum | Stripe webhook |
| trial_ends_at | timestamp | Stripe |
| current_period_end | timestamp | Stripe |
| canceled_at | timestamp | Stripe |
| grace_period_until | timestamp | calculated |

### Entitlement Enforcement
- Entitlements checked at the Action layer (not in controllers)
- Product count limit: enforced in `CreateProductAction`
- Media storage limit: enforced in `UploadProductMediaAction`
- API rate limits per plan: configured in `config/rate_limits.php`

### Failure Handling
| Scenario | Behavior |
|----------|----------|
| Payment failed | Subscription → `past_due`, grace period starts |
| Grace period (7 days) | Service continues, reminder notifications |
| Payment still failed after grace | Subscription → `canceled`, service restricted |
| Service restricted | Read-only access, no new products/passports |
| Public passports after cancellation | Remain accessible (immutable snapshots maintained) |
| Reactivation | Customer pays, subscription reactivates, full access restored |

### Webhook Inbox
- Stripe webhooks received at `/stripe/webhook`
- Signature verification mandatory
- Webhook events stored in `stripe_webhook_events` table for audit
- Processed asynchronously via jobs
- Idempotency key: `stripe_event_id`

### Downgrade Behavior
- Downgrade takes effect at period end (Stripe default)
- User notified of upcoming downgrade
- If over new plan limits at downgrade time: read-only mode, must reduce usage

### Cancellation → Data Retention
- Canceled company: read-only for 90 days, then data flagged for deletion
- Published passports remain accessible during retention period
- After retention: company data deleted, public passports return 404
- This policy aligns with GDPR data retention requirements (R3.16)

## Alternatives Considered

1. **NordiPass manages subscriptions directly**: Rejected — Stripe is the industry standard. Building a custom subscription engine is out of scope and high risk.
2. **Immediate service cutoff on payment failure**: Rejected — would destroy customer trust. Grace period is standard SaaS practice.

## Consequences

- All Stripe interactions go through webhooks (not synchronous API calls during checkout)
- Local projection is the enforcement point (fast, always available)
- Daily reconciliation prevents drift
- Public passport availability after cancellation is a commercial policy decision documented here
