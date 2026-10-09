<?php

use App\Actions\Catalog\Categories\CreateCategoryAction;
use App\Actions\Catalog\Products\SetProductManagedAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Actions\Catalog\Taxonomy\TaxonomyGovernanceAction;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\RecordPassportValidationRun;
use App\Enums\Imports\ImportMode;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Models\Catalog\Product;
use App\Models\DppProfile;
use App\Models\User;
use App\Services\Catalog\Taxonomy\ProductReadinessProfileResolver;
use App\Services\Dpp\DppProfileResolver;
use App\Services\Dpp\DppReadinessProfileBatch;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('incomplete product profile selectors fail instead of silently using a lower priority profile', function (?string $key, ?int $version, string $resolver) {
    $product = new Product;
    $product->forceFill(['readiness_profile_override' => $key, 'readiness_profile_version_override' => $version]);
    expect(fn () => app($resolver)->resolve($product))->toThrow(ValidationException::class);
})->with([['generic-espr', null], [null, 1], ['', 1], ['generic-espr', 0]])
    ->with([DppProfileResolver::class, ProductReadinessProfileResolver::class]);

test('an unknown configured fallback cannot fabricate a pilot ruleset under another profile name', function () {
    config(['passport_readiness.profile' => 'misspelled-sector', 'passport_readiness.profile_version' => 999]);
    expect(fn () => app(ReadinessProfileRepository::class)->active())->toThrow(InvalidArgumentException::class);
    expect(fn () => app(DppProfileResolver::class)->resolve(new Product))->toThrow(InvalidArgumentException::class);
});

test('assigned profiles outside their active window cannot be silently replaced by pilot', function (string $boundary) {
    $profile = new DppProfile;
    $profile->forceFill(['key' => 'synthetic-window', 'version' => 1, 'status' => $boundary === 'draft' ? 'draft' : 'active',
        'effective_from' => $boundary === 'future' ? now()->addDay() : null,
        'effective_to' => $boundary === 'expired' ? now()->subDay() : null]);
    $product = new Product;
    $product->setRelation('dppProfiles', new Collection([$profile]));
    expect(fn () => app(DppProfileResolver::class)->resolve($product, []))->toThrow(ValidationException::class);
})->with(['draft', 'future', 'expired']);

test('an inactive persisted fallback cannot bypass selection checks through config-only compatibility', function (bool $batch) {
    $profile = DppProfile::query()->where('key', config('passport_readiness.profile'))->where('version', config('passport_readiness.profile_version'))->sole();
    $profile->update(['status' => 'draft']);
    $product = new Product;
    $product->setRelation('dppProfiles', new Collection);
    $catalogue = $batch ? [DppReadinessProfileBatch::key($profile->key, $profile->version) => $profile] : null;
    expect(fn () => app(DppProfileResolver::class)->resolve($product, $catalogue))->toThrow(ValidationException::class);
})->with([false, true]);

test('imported categories select independent profiles and freeze requirements and persisted evidence', function () {
    Storage::fake('production_imports');
    $actor = User::factory()->create(['email_verified_at' => now()]);
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans'), 'billing.provider' => 'fake']);
    $company = app(CreateCompanyForOwner::class)->execute($actor, 'Synthetic sector acceptance');
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $profiles = $categories = [];
    foreach (['alpha', 'beta'] as $sector) {
        $profile = DppProfile::create(['key' => 'synthetic-'.$sector, 'name' => 'Synthetic '.$sector, 'version' => 1, 'status' => 'active']);
        $profile->requirements()->create(['key' => $sector.'.evidence', 'title' => 'Synthetic evidence', 'level' => 'required',
            'rule_code' => 'synthetic.missing.'.$sector, 'source' => 'Synthetic acceptance only']);
        $category = app(CreateCategoryAction::class)->execute($actor, $company, ['name' => ucfirst($sector), 'slug' => $sector]);
        $governance = app(TaxonomyGovernanceAction::class);
        $template = $governance->createTemplate($actor, $company, $category, ['name' => ucfirst($sector),
            'readiness_profile' => $profile->key, 'readiness_profile_version' => 1]);
        $governance->activateTemplate($actor, $company, $category, $template);
        $profiles[$sector] = $profile;
        $categories[$sector] = $category;
    }
    $job = app(CreateImportJobAction::class)->execute($actor, $company,
        UploadedFile::fake()->createWithContent('synthetic-sectors.csv', "name,slug,category\nAlpha product,alpha-product,alpha\nBeta product,beta-product,beta"), ImportMode::Create);
    $job = app(PreviewImportJobAction::class)->execute($actor, $company, $job, ['name' => 'name', 'slug' => 'slug', 'primary_category' => 'category']);
    $job = app(ApplyImportJobAction::class)->execute($actor, $company, $job);
    expect($job->created_rows)->toBe(2)->and($job->skipped_rows)->toBe(0);
    $products = $company->products()->orderBy('id')->get();
    $batch = DppReadinessProfileBatch::load($products);
    foreach ($products as $product) {
        expect($product->managed)->toBeFalse();
        $product = app(SetProductManagedAction::class)->execute($actor, $company, $product, true);
        $sector = str_starts_with($product->slug, 'alpha') ? 'alpha' : 'beta';
        expect(app(DppProfileResolver::class)->resolve($product)[0]->key)->toBe('synthetic-'.$sector)
            ->and(app(DppProfileResolver::class)->resolve($product, $batch->catalogue)[0]->key)->toBe('synthetic-'.$sector);
        $passport = app(CreateProductPassportDraftAction::class)->handle($actor, $company, $product);
        $draft = $passport->currentDraftVersion;
        $context = app(ReadinessContextBuilder::class)->build($company, $product->fresh());
        $result = app(PassportReadinessEvaluator::class)->evaluate($context);
        $rule = collect($result->rules)->firstWhere('requirementKey', $sector.'.evidence');
        expect($draft->readiness_profile)->toBe('synthetic-'.$sector)->and($context->effectiveRequirements)->toHaveCount(1)
            ->and($rule->status)->toBe(ReadinessRuleStatus::Failed)->and($rule->severity)->toBe(ReadinessSeverity::Blocker)
            ->and($rule->profile)->toBe('synthetic-'.$sector);
        $run = app(RecordPassportValidationRun::class)->handle($company, $passport, $draft, $result, $actor);
        $evidence = $run->fresh()->getAttributes();
        $profiles[$sector]->requirements()->firstOrFail()->update(['level' => 'recommended']);
        $other = $sector === 'alpha' ? 'beta' : 'alpha';
        app(UpdateProductAction::class)->execute($actor, $company, $product, ['name' => $product->name], $categories[$other]->uuid, syncCategories: true);
        $frozen = app(ReadinessContextBuilder::class)->build($company, $product->fresh());
        expect($frozen->readinessProfile->code)->toBe('synthetic-'.$sector)
            ->and($frozen->effectiveRequirements[0]->level->value)->toBe('required')
            ->and($run->fresh()->getAttributes())->toEqual($evidence);
    }
});
