<?php

namespace App\Actions\Onboarding;

use App\Actions\Catalog\Products\CreateProductAction;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use App\Services\Onboarding\PublicBetaOnboardingService;

class CreateSampleProductAction
{
    public function __construct(
        private readonly CreateProductAction $products,
        private readonly PublicBetaOnboardingService $onboarding,
    ) {}

    public function execute(User $actor, Company $company): Product
    {
        $existing = $this->onboarding->sampleProduct($company);

        if ($existing instanceof Product) {
            return $existing;
        }

        return $this->products->execute($actor, $company, [
            'name' => 'R3.18 Sample Product',
            'slug' => 'r3-18-sample-product',
            'short_description' => 'Isolated sample product for the Public Beta onboarding journey.',
            'description' => 'Use this sample to explore product setup, Passport drafting, readiness, publication, and QR verification without mixing data across companies.',
            'brand' => 'NordiPass',
            'manufacturer' => $company->name,
        ]);
    }
}
