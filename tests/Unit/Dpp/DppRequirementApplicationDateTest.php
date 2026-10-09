<?php

use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Models\Company;
use App\Models\DppProfile;
use App\Models\DppRequirement;
use App\Services\Dpp\DppRequirementEvaluator;
use App\Services\Passports\Readiness\Rules\PassportExists;
use Carbon\CarbonImmutable;

test('future requirements are not blockers until their inclusive application date', function (string $date, ReadinessRuleStatus $status) {
    $profile = (new DppProfile)->forceFill(['key' => 'date-fixture', 'version' => 1]);
    $requirement = (new DppRequirement)->forceFill([
        'key' => 'future.evidence', 'level' => 'required', 'rule_code' => 'not.implemented.yet',
        'effective_from' => '2027-02-18', 'source_version' => '2026-10-09',
    ])->setRelation('profile', $profile);
    $context = new ReadinessEvaluationContext(new Company, null, null, null, [], [], [], CarbonImmutable::parse($date));
    $result = app(DppRequirementEvaluator::class)->evaluate($requirement, $context, []);
    expect($result->status)->toBe($status)->and($result->severity)->toBe(ReadinessSeverity::Blocker)
        ->and($result->safeContext['requirement_effective_from'])->toBe('2027-02-18')
        ->and($result->safeContext['requirement_source_version'])->toBe('2026-10-09');
})->with([
    ['2027-02-17 23:59:59', ReadinessRuleStatus::NotApplicable],
    ['2027-02-18 00:00:00', ReadinessRuleStatus::Failed],
]);

test('requirements ending today still execute and become inapplicable tomorrow', function (string $date, ReadinessRuleStatus $status) {
    $requirement = (new DppRequirement)->forceFill(['key' => 'dated', 'level' => 'required',
        'effective_from' => '2026-01-01', 'effective_to' => '2026-10-09'])->setRelation('profile',
            (new DppProfile)->forceFill(['key' => 'dated-profile', 'version' => 1]));
    $context = new ReadinessEvaluationContext(new Company, null, null, null, [], [], [], CarbonImmutable::parse($date));
    expect(app(DppRequirementEvaluator::class)->evaluate($requirement, $context, [])->status)->toBe($status);
})->with([['2026-10-09 23:59:59', ReadinessRuleStatus::Failed], ['2026-10-10', ReadinessRuleStatus::NotApplicable]]);

test('application dates cannot weaken an integrity blocker', function () {
    $rule = new PassportExists;
    $requirement = (new DppRequirement)->forceFill(['key' => 'integrity', 'rule_code' => $rule->code(),
        'level' => 'recommended', 'effective_from' => '2099-01-01'])->setRelation('profile',
            (new DppProfile)->forceFill(['key' => 'integrity-profile', 'version' => 1]));
    $context = new ReadinessEvaluationContext(new Company, null, null, null, [], [], [], CarbonImmutable::parse('2026-10-09'));
    $result = app(DppRequirementEvaluator::class)->evaluate($requirement, $context, [$rule->code() => $rule]);
    expect($result->status)->toBe(ReadinessRuleStatus::Failed)->and($result->severity)->toBe(ReadinessSeverity::Blocker)
        ->and($result->safeContext['evaluation_error'])->toBe('unsupported_configuration');
});

test('a reversed application window is a configuration blocker', function () {
    $requirement = (new DppRequirement)->forceFill(['key' => 'invalid-dates', 'level' => 'required',
        'effective_from' => '2027-02-18', 'effective_to' => '2026-01-01'])->setRelation('profile',
            (new DppProfile)->forceFill(['key' => 'invalid-profile', 'version' => 1]));
    $context = new ReadinessEvaluationContext(new Company, null, null, null, [], [], [], CarbonImmutable::parse('2026-10-09'));
    $result = app(DppRequirementEvaluator::class)->evaluate($requirement, $context, []);
    expect($result->status)->toBe(ReadinessRuleStatus::Failed)->and($result->safeContext['evaluation_error'])->toBe('invalid_application_window');
});
