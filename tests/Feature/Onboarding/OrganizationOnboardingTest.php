<?php

use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\CompanyRole;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;

test('a user without a company can create an organization with owner membership and trial', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($user);

    $this->get(route('companies.none'))->assertOk()->assertSee('Create an organization');
    $this->post(route('companies.create.store'), ['name' => 'Beta Organization', 'country_code' => 'SE'])
        ->assertRedirect(route('onboarding.index'));

    $company = Company::query()->where('name', 'Beta Organization')->firstOrFail();
    $membership = CompanyMembership::query()->where('company_id', $company->getKey())->where('user_id', $user->getKey())->firstOrFail();
    $subscription = CompanySubscription::query()->where('company_id', $company->getKey())->firstOrFail();

    expect($membership->role)->toBe(CompanyRole::Owner)
        ->and($membership->is_owner)->toBeTrue()
        ->and($subscription->status)->toBe(BillingSubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at)->not->toBeNull()
        ->and($subscription->trial_ends_at->isFuture())->toBeTrue();
});
