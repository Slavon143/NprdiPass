<?php

namespace App\Services\Billing;

use App\Data\Billing\StripeCheckoutSession;
use App\Data\Billing\StripePortalSession;
use App\Data\Billing\StripeSubscriptionProjection;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Exceptions\Billing\BillingProviderException;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Services\Billing\Contracts\StripeBillingProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HttpStripeBillingProvider implements StripeBillingProvider
{
    public function createCheckoutSession(Company $company, string $planKey, string $successUrl, string $cancelUrl): StripeCheckoutSession
    {
        $plan = (array) config("billing.plans.{$planKey}");
        $priceId = (string) ($plan['stripe_price_id'] ?? '');
        if ($priceId === '') {
            throw new BillingProviderException("Stripe price is not configured for plan {$planKey}.");
        }

        $trialDays = (int) ($plan['trial_days'] ?? 0);
        $subscriptionData = [
            'metadata' => [
                'company_uuid' => $company->uuid,
                'plan_key' => $planKey,
            ],
        ];
        if ($trialDays > 0) {
            $subscriptionData['trial_period_days'] = $trialDays;
        }

        $response = $this->send(fn () => $this->request()
            ->asForm()
            ->post($this->url('checkout/sessions'), [
                'mode' => 'subscription',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => $company->uuid,
                'customer_email' => $company->billing_email,
                'metadata' => [
                    'company_uuid' => $company->uuid,
                    'plan_key' => $planKey,
                ],
                'line_items' => [[
                    'price' => $priceId,
                    'quantity' => 1,
                ]],
                'subscription_data' => $subscriptionData,
            ]), 'Stripe checkout session creation failed.');

        return new StripeCheckoutSession(
            id: (string) $response->json('id'),
            url: (string) $response->json('url'),
        );
    }

    public function createPortalSession(Company $company, CompanySubscription $subscription, string $returnUrl): StripePortalSession
    {
        $customerId = (string) $subscription->getAttribute('stripe_customer_id');
        if ($customerId === '') {
            throw new BillingProviderException('Stripe customer is not available for this company.');
        }

        $response = $this->send(fn () => $this->request()
            ->asForm()
            ->post($this->url('billing_portal/sessions'), [
                'customer' => $customerId,
                'return_url' => $returnUrl,
            ]), 'Stripe portal session creation failed.');

        return new StripePortalSession(
            id: (string) $response->json('id'),
            url: (string) $response->json('url'),
        );
    }

    public function retrieveSubscription(string $stripeSubscriptionId): StripeSubscriptionProjection
    {
        $response = $this->send(fn () => $this->request()
            ->get($this->url('subscriptions/'.$stripeSubscriptionId)), 'Stripe subscription retrieval failed.');

        $payload = $response->json();
        if (! is_array($payload)) {
            throw BillingProviderException::retryable('Stripe subscription response is unreadable.');
        }

        return $this->projectionFromSubscription($payload);
    }

    public function listSubscriptions(string $stripeCustomerId): array
    {
        if ($stripeCustomerId === '') {
            return [];
        }

        $response = $this->send(fn () => $this->request()
            ->get($this->url('subscriptions'), [
                'customer' => $stripeCustomerId,
                'status' => 'all',
                'limit' => 100,
                'expand' => ['data.items.data.price'],
            ]), 'Stripe subscription listing failed.');

        $payload = $response->json();
        if (! is_array($payload)) {
            throw BillingProviderException::retryable('Stripe subscription listing is unreadable.');
        }

        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): ?StripeSubscriptionProjection => is_array($item) ? $this->projectionFromSubscription($item) : null,
            $data,
        )));
    }

    /** @param array<string, mixed> $payload */
    public function projectionFromSubscription(array $payload): StripeSubscriptionProjection
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        // Modern Stripe API versions expose the billing period on the subscription item,
        // not on the subscription object itself.
        $item = $this->relevantSubscriptionItem($payload);
        $price = is_array($item['price'] ?? null) ? $item['price'] : [];
        $priceId = isset($price['id']) ? (string) $price['id'] : null;

        return new StripeSubscriptionProjection(
            stripeCustomerId: (string) ($payload['customer'] ?? ''),
            stripeSubscriptionId: (string) ($payload['id'] ?? ''),
            stripePriceId: $priceId,
            planKey: $this->planKeyForPrice($priceId) ?? (string) ($metadata['plan_key'] ?? config('billing.default_plan', 'pilot')),
            status: BillingSubscriptionStatus::tryFrom((string) ($payload['status'] ?? 'incomplete')) ?? BillingSubscriptionStatus::Incomplete,
            trialEndsAt: $this->timestamp($payload['trial_end'] ?? null),
            currentPeriodStart: $this->timestamp($item['current_period_start'] ?? null),
            currentPeriodEnd: $this->timestamp($item['current_period_end'] ?? null),
            canceledAt: $this->timestamp($payload['canceled_at'] ?? null),
            metadata: $metadata,
        );
    }

    /**
     * Resolve the subscription item that carries the billing period for this subscription.
     * For a normal single-price subscription this is the item whose price belongs to a
     * configured plan; otherwise the first item is used as a fallback.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function relevantSubscriptionItem(array $payload): array
    {
        $items = $payload['items']['data'] ?? [];
        if (! is_array($items) || $items === []) {
            return [];
        }

        $fallback = null;
        foreach ($items as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $price = is_array($candidate['price'] ?? null) ? $candidate['price'] : [];
            $priceId = isset($price['id']) ? (string) $price['id'] : null;
            if ($priceId !== null && $this->planKeyForPrice($priceId) !== null) {
                return $candidate;
            }

            $fallback ??= $candidate;
        }

        return $fallback ?? [];
    }

    /** @return Response */
    private function send(callable $request, string $failureMessage)
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw BillingProviderException::retryable($failureMessage.' '.$exception->getMessage());
        }

        if ($response->failed()) {
            $message = (string) ($response->json('error.message') ?: $failureMessage);

            throw match (true) {
                $response->status() === 429 || $response->serverError() => BillingProviderException::retryable($message, $response->status()),
                default => new BillingProviderException($message, false, $response->status()),
            };
        }

        return $response;
    }

    private function request()
    {
        $secret = (string) config('billing.stripe_secret');
        if ($secret === '') {
            throw new BillingProviderException('Stripe secret key is not configured.');
        }

        $request = Http::acceptJson()->withToken($secret)->timeout(30);

        $caBundle = (string) config('billing.ca_bundle');
        if ($caBundle !== '') {
            $request->withOptions(['verify' => $caBundle]);
        }

        return $request;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('billing.stripe_api_base'), '/').'/'.ltrim($path, '/');
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_numeric($value) && (int) $value > 0
            ? CarbonImmutable::createFromTimestamp((int) $value)
            : null;
    }

    private function planKeyForPrice(?string $priceId): ?string
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        foreach ((array) config('billing.plans') as $key => $plan) {
            if (is_array($plan) && ($plan['stripe_price_id'] ?? null) === $priceId) {
                return (string) $key;
            }
        }

        return null;
    }
}
