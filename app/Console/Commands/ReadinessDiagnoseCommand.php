<?php

namespace App\Console\Commands;

use App\Models\Passports\ProductPassport;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use Illuminate\Console\Command;

class ReadinessDiagnoseCommand extends Command
{
    protected $signature = 'nordipass:readiness-diagnose {passportUuid}';

    protected $description = 'Evaluate a passport readiness result and print a safe diagnostic summary.';

    public function handle(
        ReadinessContextBuilder $contextBuilder,
        PassportReadinessEvaluator $evaluator,
    ): int {
        $passport = ProductPassport::query()
            ->with(['company', 'product'])
            ->where('uuid', (string) $this->argument('passportUuid'))
            ->first();

        if ($passport === null) {
            $this->error('Passport not found.');

            return self::FAILURE;
        }

        $result = $evaluator->evaluate($contextBuilder->build($passport->company, $passport->product));

        $this->line(json_encode([
            'passport_uuid' => $result->passportUuid,
            'draft_version_uuid' => $result->draftVersionUuid,
            'draft_revision' => $result->passportRevision,
            'schema_version' => $result->schemaVersion,
            'profile' => $result->profile,
            'profile_version' => $result->profileVersion,
            'rule_set_version' => $result->ruleSetVersion,
            'rule_set_fingerprint' => $result->ruleSetFingerprint,
            'score_algorithm' => $result->scoreAlgorithm,
            'score_algorithm_version' => $result->scoreAlgorithmVersion,
            'status' => $result->status->value,
            'score' => $result->score,
            'score_breakdown' => $result->scoreBreakdown->toArray(),
            'counts' => [
                'passed' => $result->counts->passed,
                'failed_blockers' => $result->counts->blockers,
                'failed_warnings' => $result->counts->warnings,
                'failed_recommendations' => $result->counts->recommendations,
                'not_applicable' => $result->counts->notApplicable,
            ],
            'failed_rules' => array_values(array_map(
                fn ($rule): array => [
                    'code' => $rule->code,
                    'group' => $rule->group->value,
                    'severity' => $rule->severity->value,
                    'status' => $rule->status->value,
                    'section' => $rule->section?->value,
                    'field' => $rule->field,
                ],
                array_filter($result->rules, fn ($rule): bool => $rule->status->value === 'failed'),
            )),
            'evaluated_at' => $result->evaluatedAt->toISOString(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
