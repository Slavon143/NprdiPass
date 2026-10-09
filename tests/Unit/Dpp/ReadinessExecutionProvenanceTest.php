<?php

use App\Services\Dpp\ReadinessExecutionProvenance;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\Rules\PassportExists;

test('execution provenance identifies real handler bindings independently of configuration fingerprints', function () {
    $context = requirementExecutionContext([
        ['key' => 'independent.required', 'rule_code' => 'independent.evidence', 'rule_class' => IndependentDppFailureRule::class],
    ]);
    $first = app(PassportReadinessEvaluator::class)->evaluate($context);
    app()->bind(IndependentDppFailureRule::class, fn () => new class extends IndependentDppFailureRule {});
    $second = app(PassportReadinessEvaluator::class)->evaluate($context);
    expect($second->ruleSetFingerprint)->toBe($first->ruleSetFingerprint)
        ->and($second->profileDetails['execution']['fingerprint'])->not->toBe($first->profileDetails['execution']['fingerprint'])
        ->and($second->profileDetails['execution']['replay_guarantee'])->toBe('provenance_only')
        ->and(json_encode($second->profileDetails['execution']))->not->toContain(base_path())
        ->and($second->profileDetails['execution_comparison'])->toBe('legacy_unknown');
});

test('provenance hashes are stable across handler ordering and expose no filesystem paths', function () {
    $service = app(ReadinessExecutionProvenance::class);
    $first = $service->capture([new IndependentDppFailureRule, new PassportExists]);
    $second = $service->capture([new PassportExists, new IndependentDppFailureRule]);
    expect($first)->toBe($second)->and(json_encode($first))->not->toContain(base_path())
        ->and($first['release']['application_sha256'])->toHaveLength(64)
        ->and($first['release']['dependencies_sha256'])->toHaveLength(64);
});
