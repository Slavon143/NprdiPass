<?php

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\RecordPassportValidationRun;
use App\Enums\CompanyRole;
use App\Models\CompanyMembership;
use App\Models\DppProfile;
use App\Models\User;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Tenancy\Contracts\CurrentCompany;

test('draft creation selects an explicitly assigned independent profile and freezes its requirements', function () {
    $context = requirementExecutionContext([
        ['key' => 'independent.required', 'rule_code' => 'independent.evidence', 'rule_class' => IndependentDppFailureRule::class],
    ]);
    $profile = $context->dppProfiles[0];
    $context->product->dppProfiles()->attach($profile, ['assignment_reason' => 'Explicit sector assignment']);
    $actor = User::factory()->create();
    CompanyMembership::factory()->create(['company_id' => $context->company->id, 'user_id' => $actor->id, 'role' => CompanyRole::Owner]);
    app(CurrentCompany::class)->set($context->company);
    $passport = app(CreateProductPassportDraftAction::class)->handle($actor, $context->company, $context->product);
    expect($passport->currentDraftVersion->readiness_profile)->toBe('independent-test');

    $profile->requirements()->firstOrFail()->update(['level' => 'recommended', 'source' => 'Changed later']);
    $context->product->dppProfiles()->detach();
    $frozen = app(ReadinessContextBuilder::class)->build($context->company, $context->product->fresh());
    expect($frozen->effectiveRequirements)->toHaveCount(1)
        ->and($frozen->effectiveRequirements[0]->level->value)->toBe('required')
        ->and($frozen->effectiveRequirements[0]->source)->toBeNull()
        ->and($frozen->dppProfiles[0]->pivot->assignment_reason)->toBe('Explicit sector assignment');
    $evaluated = app(PassportReadinessEvaluator::class)->evaluate($frozen);
    expect($evaluated->profileDetails['execution_comparison'])->toBe('matched');
    $run = app(RecordPassportValidationRun::class)->handle(
        $context->company, $passport, $passport->currentDraftVersion, $evaluated, $actor,
    );
    expect($run->profile_snapshot['profile_details']['execution'])->toEqual($evaluated->profileDetails['execution']);
    $snapshot = $passport->currentDraftVersion->readiness_profile_snapshot;
    $snapshot['execution']['fingerprint'] = str_repeat('0', 64);
    $passport->currentDraftVersion->forceFill(['readiness_profile_snapshot' => $snapshot])->save();
    $changedContext = app(ReadinessContextBuilder::class)->build($context->company, $context->product->fresh());
    $changed = app(PassportReadinessEvaluator::class)->evaluate($changedContext);
    expect($changed->profileDetails['execution_comparison'])->toBe('changed')
        ->and($run->fresh()->profile_snapshot['profile_details']['execution'])->toEqual($evaluated->profileDetails['execution']);
    $profile->delete();
    $restored = app(ReadinessContextBuilder::class)->build($context->company, $context->product->fresh());
    expect($restored->readinessProfile->code)->toBe('independent-test')->and($restored->effectiveRequirements)->toHaveCount(1);
});

test('every assigned profile survives draft creation and readiness context construction', function () {
    $context = requirementExecutionContext([['key' => 'first.required', 'rule_code' => 'independent.evidence', 'rule_class' => IndependentDppFailureRule::class]]);
    $second = DppProfile::create(['key' => 'second-sector', 'name' => 'Second', 'version' => 1]);
    $second->requirements()->create(['key' => 'second.required', 'title' => 'Second evidence', 'level' => 'required', 'rule_code' => 'missing.second']);
    $context->product->dppProfiles()->attach([$context->dppProfiles[0]->id => ['priority' => 0], $second->id => ['priority' => 1]]);
    $actor = User::factory()->create();
    CompanyMembership::factory()->create(['company_id' => $context->company->id, 'user_id' => $actor->id, 'role' => CompanyRole::Owner]);
    app(CurrentCompany::class)->set($context->company);
    app(CreateProductPassportDraftAction::class)->handle($actor, $context->company, $context->product);
    $resolved = app(ReadinessContextBuilder::class)->build($context->company, $context->product->fresh());
    expect($resolved->dppProfiles)->toHaveCount(2)->and($resolved->effectiveRequirements)->toHaveCount(2);
});
