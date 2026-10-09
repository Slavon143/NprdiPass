<?php

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\Passports\DppSectionKey;
use App\Models\Billing\CompanySubscription;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Onboarding\PublicBetaOnboardingService;
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Storage;

function r318JourneyTenant(): array
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create(['settings' => ['support_email' => 'support@example.test']]);

    CompanyMembership::factory()
        ->for($actor, 'user')
        ->for($company)
        ->owner()
        ->create();

    CompanySubscription::query()->create([
        'company_id' => $company->getKey(),
        'stripe_customer_id' => 'cus_journey',
        'stripe_subscription_id' => 'sub_journey',
        'plan_key' => 'pilot',
        'status' => BillingSubscriptionStatus::Active,
        'entitlements' => ['public_passports.enabled' => true],
    ]);

    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

function r318JourneyFillSection(User $actor, Company $company, Product $product, DppSectionKey $section, array $payload, int &$revision): void
{
    $passport = ProductPassport::query()
        ->forCompany($company)
        ->where('product_id', $product->getKey())
        ->firstOrFail();

    $result = app(UpdateProductPassportSectionAction::class)->handle(
        $actor,
        $company,
        $product,
        $passport,
        $section->value,
        $payload,
        $revision,
    );

    $revision = $result->currentDraftVersion->draft_revision;
}

function r318JourneyFillAllSections(User $actor, Company $company, Product $product, int &$revision): void
{
    r318JourneyFillSection($actor, $company, $product, DppSectionKey::Identity, [
        'public_name' => 'Sample Public Name',
        'public_description' => 'Sample public description for the onboarding journey.',
    ], $revision);

    r318JourneyFillSection($actor, $company, $product, DppSectionKey::ManufacturerAndOperator, [
        'manufacturer_display_name' => $company->name,
        'responsible_operator_display_name' => $company->name.' Operator',
        'contact_notes' => 'Sample contact notes.',
    ], $revision);

    r318JourneyInjectManufacturerContact($actor, $company, $product, $revision);

    r318JourneyFillSection($actor, $company, $product, DppSectionKey::Safety, [
        'warnings' => ['Sample warning'],
        'storage_instructions' => 'Sample storage instructions.',
    ], $revision);

    r318JourneyFillSection($actor, $company, $product, DppSectionKey::RecyclingAndDisposal, [
        'recycling_instructions' => 'Sample recycling instructions.',
    ], $revision);

    r318JourneyFillSection($actor, $company, $product, DppSectionKey::MaterialsAndComposition, [
        'materials' => [
            ['name' => 'Plastic', 'percentage' => 100.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
        ],
    ], $revision);

    r318JourneyFillSection($actor, $company, $product, DppSectionKey::EnvironmentalInformation, [
        'carbon_footprint_kg_co2e' => 10.0,
    ], $revision);

    r318JourneyFillSection($actor, $company, $product, DppSectionKey::UsageAndCare, [
        'usage_instructions' => 'Sample usage instructions.',
    ], $revision);

    r318JourneyFillSection($actor, $company, $product, DppSectionKey::RepairAndSpareParts, [
        'repair_instructions' => 'Sample repair instructions.',
    ], $revision);

    r318JourneyFillSection($actor, $company, $product, DppSectionKey::SupportAndContact, [
        'warranty_summary' => 'Sample warranty summary.',
    ], $revision);
}

function r318JourneyInjectManufacturerContact(User $actor, Company $company, Product $product, int &$revision): void
{
    $draft = ProductPassport::query()
        ->forCompany($company)
        ->where('product_id', $product->getKey())
        ->firstOrFail()
        ->currentDraftVersion;

    $payload = $draft->payload;
    $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@sample-mfg.example';

    $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

    $newRevision = $draft->draft_revision + 1;
    $draft->setAttribute('payload', $normalized);
    $draft->setAttribute('draft_revision', $newRevision);
    $draft->setAttribute('updated_by', $actor->getKey());
    $draft->save();

    $revision = $newRevision;
}

test('public beta onboarding journey completes end to end', function () {
    [$actor, $company] = r318JourneyTenant();

    Storage::fake('catalog_media');

    // Dashboard exposes the onboarding path.
    $this->actingAs($actor)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('onboarding.index'));

    // Onboarding starts incomplete.
    $this->actingAs($actor)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertSee('Launch path')
        ->assertSee('In progress');

    // Sample product creation is the tenant-scoped entry point.
    $this->actingAs($actor)
        ->post(route('onboarding.sample-product.store'))
        ->assertRedirect();

    $product = Product::query()
        ->forCompany($company)
        ->where('slug_normalized', 'r3-18-sample-product')
        ->firstOrFail();

    $this->actingAs($actor)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertSee('R3.18 Sample Product');

    // Give the sample product the identifier and media the readiness profile expects.
    $variant = $product->defaultVariant;
    $variant->forceFill(['sku' => 'SAMPLE-001', 'sku_normalized' => 'sample-001'])->save();

    Storage::disk('catalog_media')->put('onboarding/sample.jpg', 'fake content');

    $media = new ProductMedia;
    $media->forceFill([
        'uuid' => (string) str()->uuid(),
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'original_filename' => 'sample.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1024,
        'storage_path' => 'onboarding/sample.jpg',
        'checksum_sha256' => str_repeat('a', 64),
        'sort_order' => 0,
        'uploaded_by' => $actor->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $product->forceFill(['primary_media_id' => $media->getKey()])->save();
    $product->refresh();

    // Draft the Passport and fill the sections through the domain actions.
    $passport = app(CreateProductPassportDraftAction::class)->handle($actor, $company, $product);
    $revision = $passport->currentDraftVersion->draft_revision;

    r318JourneyFillAllSections($actor, $company, $product, $revision);

    // Readiness page renders for the drafted Passport.
    $this->actingAs($actor)
        ->get(route('catalog.products.passport.readiness', $product->uuid))
        ->assertOk();

    // Publish the first Passport.
    $passport = ProductPassport::query()
        ->forCompany($company)
        ->where('product_id', $product->getKey())
        ->firstOrFail()
        ->fresh(['currentDraftVersion', 'currentPublishedVersion']);

    $result = app(PublishProductPassport::class)->handle(
        $actor,
        $company,
        $product,
        $passport,
        $revision,
        true,
    );

    expect($result->passport->current_published_version_id)->not->toBeNull();

    // Onboarding snapshot is complete and the banner flips to ready.
    $snapshot = app(PublicBetaOnboardingService::class)->snapshot($company);

    expect($snapshot['complete'])->toBeTrue()
        ->and($snapshot['completed_steps'])->toBe($snapshot['total_steps'])
        ->and($snapshot['steps']['publication']['complete'])->toBeTrue()
        ->and($snapshot['steps']['qr']['complete'])->toBeTrue();

    $this->actingAs($actor)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertSee('Ready for beta');

    // QR verification page renders after first publication.
    $this->actingAs($actor)
        ->get(route('catalog.products.passport.qr.show', $product->uuid))
        ->assertOk();

    // The stable public URL serves the published Passport.
    $this->get(route('public.passports.show', ['publicId' => $passport->refresh()->public_id]))
        ->assertOk();

    // The support path remains reachable at the end of the journey.
    $this->actingAs($actor)
        ->get(route('onboarding.support'))
        ->assertOk()
        ->assertSee('Billing recovery');
});
