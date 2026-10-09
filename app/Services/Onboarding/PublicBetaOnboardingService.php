<?php

namespace App\Services\Onboarding;

use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Services\Billing\EntitlementService;

class PublicBetaOnboardingService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Company $company): array
    {
        $firstProduct = Product::query()
            ->forCompany($company)
            ->oldest('id')
            ->first();
        $firstPassport = ProductPassport::query()
            ->forCompany($company)
            ->with('product')
            ->oldest('id')
            ->first();
        $publishedPassport = ProductPassport::query()
            ->forCompany($company)
            ->whereNotNull('current_published_version_id')
            ->with('product')
            ->oldest('id')
            ->first();
        $subscription = CompanySubscription::query()
            ->forCompany($company)
            ->first();

        $billingComplete = $this->billingComplete($subscription);
        $publicEnabled = $this->entitlements->enabled($company, 'public_passports.enabled');

        $steps = [
            'billing' => [
                'label' => 'Activate billing',
                'complete' => $billingComplete,
                'url' => route('settings.billing.index'),
                'detail' => $subscription instanceof CompanySubscription
                    ? 'Subscription status: '.$subscription->status->value.($billingComplete ? '' : ' — open billing to resume the journey.')
                    : 'Choose a plan to unlock the public beta journey.',
            ],
            'sample_data' => [
                'label' => 'Explore sample data',
                'complete' => $this->sampleProduct($company) instanceof Product,
                'url' => route('onboarding.sample-product.store'),
                'detail' => 'Create one isolated sample product in this company only.',
                'method' => 'post',
            ],
            'first_product' => [
                'label' => 'Create first product',
                'complete' => $firstProduct instanceof Product,
                'url' => $firstProduct instanceof Product
                    ? route('catalog.products.show', $firstProduct->uuid)
                    : route('catalog.products.create'),
                'detail' => $firstProduct instanceof Product
                    ? $firstProduct->name
                    : 'Add the product that will receive the first Digital Product Passport.',
            ],
            'passport' => [
                'label' => 'Create Passport draft',
                'complete' => $firstPassport instanceof ProductPassport,
                'url' => $firstProduct instanceof Product
                    ? ($firstPassport instanceof ProductPassport
                        ? route('catalog.products.passport.edit', $firstPassport->product->uuid)
                        : route('catalog.products.show', $firstProduct->uuid))
                    : route('catalog.products.index'),
                'detail' => $firstPassport instanceof ProductPassport
                    ? 'Draft exists for '.$firstPassport->product->name
                    : 'Open a product and create its Passport draft.',
            ],
            'readiness' => [
                'label' => 'Review readiness',
                'complete' => $firstPassport instanceof ProductPassport,
                'url' => $firstPassport instanceof ProductPassport
                    ? route('catalog.products.passport.readiness', $firstPassport->product->uuid)
                    : route('catalog.products.index'),
                'detail' => 'Use readiness blockers and warnings before publication.',
            ],
            'publication' => [
                'label' => 'Publish first Passport',
                'complete' => $publicEnabled && $publishedPassport instanceof ProductPassport,
                'entitlement_required' => $publicEnabled ? null : 'public_passports.enabled',
                'url' => $publicEnabled
                    ? ($firstPassport instanceof ProductPassport
                        ? route('catalog.products.passport.publish-confirm', $firstPassport->product->uuid)
                        : route('catalog.products.index'))
                    : route('settings.billing.index'),
                'detail' => ! $publicEnabled
                    ? 'Publication requires the public passports entitlement on your plan.'
                    : ($publishedPassport instanceof ProductPassport
                        ? 'Stable public URL is ready.'
                        : 'Publish after readiness blockers are resolved.'),
            ],
            'qr' => [
                'label' => 'Verify QR',
                'complete' => $publicEnabled && $publishedPassport instanceof ProductPassport,
                'entitlement_required' => $publicEnabled ? null : 'public_passports.enabled',
                'url' => $publicEnabled
                    ? ($publishedPassport instanceof ProductPassport
                        ? route('catalog.products.passport.qr.show', $publishedPassport->product->uuid)
                        : route('catalog.products.index'))
                    : route('settings.billing.index'),
                'detail' => ! $publicEnabled
                    ? 'QR labels are available once public passports are enabled.'
                    : 'Download or print the QR after first publication.',
            ],
            'support' => [
                'label' => 'Know the support path',
                'complete' => $this->supportConfigured($company),
                'url' => route('onboarding.support'),
                'detail' => $this->supportConfigured($company)
                    ? 'Support contact is configured.'
                    : 'Add support and recovery details before inviting beta users.',
            ],
        ];

        $completed = collect($steps)->filter(fn (array $step): bool => (bool) $step['complete'])->count();

        return [
            'company' => [
                'uuid' => $company->uuid,
                'name' => $company->name,
            ],
            'product_count' => Product::query()->forCompany($company)->count(),
            'passport_count' => ProductPassport::query()->forCompany($company)->count(),
            'published_passport_count' => ProductPassport::query()->forCompany($company)
                ->whereNotNull('current_published_version_id')
                ->count(),
            'public_passports_enabled' => $this->entitlements->enabled($company, 'public_passports.enabled'),
            'steps' => $steps,
            'completed_steps' => $completed,
            'total_steps' => count($steps),
            'complete' => $completed === count($steps),
        ];
    }

    public function sampleProduct(Company $company): ?Product
    {
        return Product::query()
            ->forCompany($company)
            ->where('slug_normalized', 'r3-18-sample-product')
            ->first();
    }

    private function billingComplete(?CompanySubscription $subscription): bool
    {
        return $subscription instanceof CompanySubscription
            && in_array($subscription->status, [
                BillingSubscriptionStatus::Active,
                BillingSubscriptionStatus::Trialing,
            ], true);
    }

    private function supportConfigured(Company $company): bool
    {
        $settings = $company->settings ?? [];

        return is_string($settings['support_email'] ?? null)
            && filter_var($settings['support_email'], FILTER_VALIDATE_EMAIL) !== false;
    }
}
