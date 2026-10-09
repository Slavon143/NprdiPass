# Billing provider error disclosure — 2026-10-03

The reported local Billing page showed Stripe's expired-test-key response twice.
Read-only inspection confirms APP_ENV=local, BILLING_PROVIDER=stripe, a test
secret is present and Laravel configuration is not cached. The key value was
not printed, copied into evidence or sent to a provider. No Stripe account or
credential configuration was changed.

`HttpStripeBillingProvider::send` previously used upstream `error.message` and
raw transport exception details as its exception message. Billing controllers
flash that message; commands and exception reporting can also receive it.
The provider now emits its own operation-specific messages, with dedicated
401 authentication and 403 permission guidance. Status and retryability remain
unchanged: connection failures, 429 and server failures are retryable. There
is no upstream exception chaining or raw response logging.

The Billing view also rendered success/error flash messages already rendered
by the shared application layout. Its duplicate blocks were removed; checkout
status notices remain. A page regression checks exactly one authentication
message, no synthetic credential canary and no retained claim on definite 401.

Seven negative controls fail on the saved baseline for their intended raw-message
assertions (seven assertions). The fixed complete Billing feature suite passes
**56 tests / 248 assertions**, 23.588 seconds, JUnit errors/failures zero, exit 0.
Pint passes the three edited PHP files; PHPStan passes with zero errors.
Early test-startup attempts failed because the prior stage had removed isolated
storage; recreating its configured storage/framework directories resolved this.
Those startup errors are not application failures or negative-control evidence.

HTTP and queues are faked; tests use an owned disposable MySQL `_testing` schema.
The normal database and real payment APIs are untouched. After workers finish,
the exact test schema and verified owned storage are removed. Evidence and
the independently materialized `21-billing-safe-provider-errors.patch` remain in
the prior Billing evidence directory's `credential-errors` subdirectory. This
patch follows unit 20 and preserves its saved continuation state.

Payment authentication remains blocked until an operator replaces STRIPE_SECRET
with a valid key for the existing Stripe account/test environment. Prefer a test
restricted key with the integration's required permissions. Existing price IDs
must belong to that same environment. Do not send keys in chat or commit .env.
No automatic switch to simulated billing or a different Stripe account was made.

Primary reference: [Stripe API keys](https://docs.stripe.com/keys), including
expired-key behavior, rotation and restricted keys.
