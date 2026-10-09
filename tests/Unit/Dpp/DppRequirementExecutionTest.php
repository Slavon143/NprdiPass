<?php

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\DppProfile;
use App\Services\Dpp\EffectiveDppRequirementSet;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Carbon\CarbonImmutable;

class IndependentDppFailureRule implements PassportReadinessRule
{
    public function code(): string
    {
        return 'independent.evidence';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Environmental;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        return new ReadinessRuleResult($this->code(), $this->group(), $this->severity(), ReadinessRuleStatus::Failed,
            'readiness.dpp.requirement.title', 'readiness.dpp.requirement.failed');
    }
}

function requirementExecutionContext(array $definitions): ReadinessEvaluationContext
{
    $profile = DppProfile::create(['key' => 'independent-test', 'name' => 'Independent', 'version' => 1]);
    foreach ($definitions as $definition) {
        $profile->requirements()->create($definition + ['title' => 'Evidence requirement', 'level' => 'required']);
    }

    $company = Company::factory()->create();
    $product = new Product;
    $product->forceFill([
        'uuid' => (string) str()->uuid(), 'company_id' => $company->id, 'name' => 'Requirement fixture',
        'slug' => 'requirement-fixture', 'slug_normalized' => 'requirement-fixture', 'status' => 'active',
    ])->save();

    return new ReadinessEvaluationContext(
        $company, $product, null, null, [], [], [], CarbonImmutable::now(),
        app(ReadinessProfileRepository::class)->active(), config('passport_readiness'),
        [$profile], app(EffectiveDppRequirementSet::class)->resolve([$profile]),
    );
}

test('an unknown required requirement has a failed result rather than disappearing', function () {
    $result = app(PassportReadinessEvaluator::class)->evaluate(requirementExecutionContext([
        ['key' => 'missing.handler', 'rule_code' => 'missing.handler'],
    ]));
    $rule = collect($result->rules)->firstWhere('requirementKey', 'missing.handler');
    expect($rule)->not->toBeNull()->and($rule->status)->toBe(ReadinessRuleStatus::Failed)
        ->and($rule->severity)->toBe(ReadinessSeverity::Blocker);
});

test('independent requirements execute their class and preserve separate levels', function () {
    $result = app(PassportReadinessEvaluator::class)->evaluate(requirementExecutionContext([
        ['key' => 'required.evidence', 'rule_code' => 'independent.evidence', 'rule_class' => IndependentDppFailureRule::class],
        ['key' => 'recommended.evidence', 'rule_code' => 'independent.evidence', 'rule_class' => IndependentDppFailureRule::class, 'level' => 'recommended'],
    ]));
    $required = collect($result->rules)->firstWhere('requirementKey', 'required.evidence');
    $recommended = collect($result->rules)->firstWhere('requirementKey', 'recommended.evidence');
    expect($required)->not->toBeNull()->and($recommended)->not->toBeNull()
        ->and($required->severity)->toBe(ReadinessSeverity::Blocker)
        ->and($recommended->severity)->toBe(ReadinessSeverity::Recommendation)
        ->and($required->profile)->toBe('independent-test');
});

test('unsupported requirement configuration fails closed', function () {
    $result = app(PassportReadinessEvaluator::class)->evaluate(requirementExecutionContext([
        ['key' => 'configured.evidence', 'rule_code' => 'independent.evidence', 'rule_class' => IndependentDppFailureRule::class,
            'evaluation_config' => ['unknown_policy' => true]],
    ]));
    $rule = collect($result->rules)->firstWhere('requirementKey', 'configured.evidence');
    expect($rule)->not->toBeNull()->and($rule->safeContext['evaluation_error'])->toBe('unsupported_configuration');
});

test('a profile cannot disable a publication integrity check using applicability or configuration', function () {
    foreach ([['when_section_enabled' => 'safety'], ['context_config' => ['max_payload_bytes' => PHP_INT_MAX]]] as $config) {
        $context = requirementExecutionContext([
            ['key' => 'integrity', 'rule_code' => 'passport.exists', 'level' => 'recommended', 'evaluation_config' => $config],
        ]);
        $result = app(PassportReadinessEvaluator::class)->evaluate($context);
        $rule = collect($result->rules)->firstWhere('requirementKey', 'integrity');
        expect($rule->status)->toBe(ReadinessRuleStatus::Failed)
            ->and($rule->severity)->toBe(ReadinessSeverity::Blocker)
            ->and($rule->safeContext['evaluation_error'])->toBe('unsupported_configuration');
        $context->dppProfiles[0]->delete();
    }
});
