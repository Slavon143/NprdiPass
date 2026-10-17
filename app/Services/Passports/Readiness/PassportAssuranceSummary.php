<?php

namespace App\Services\Passports\Readiness;

use App\Data\Passports\Readiness\PassportReadinessResult;

class PassportAssuranceSummary
{
    /** @return array<string, array{status: string, title: string, message: string}> */
    public function forResult(PassportReadinessResult $result): array
    {
        $scope = $result->profileDetails['regulatory_scope']['status'] ?? 'not_assessed';
        $requirements = match ($scope) {
            'not_assessed' => 'not_assessed',
            'invalid', 'assessment_incomplete', 'sector_assessment_required' => 'assessment_required',
            'not_applicable' => 'limited_scope_exception',
            default => 'coverage_unverified',
        };

        return [
            'publication' => ['status' => $result->status->value, 'title' => 'assurance.publication.title',
                'message' => 'assurance.publication.'.$result->status->value],
            'requirements' => ['status' => $requirements, 'title' => 'assurance.requirements.title',
                'message' => 'assurance.requirements.'.$requirements],
            // The current registry transport has no submission or receipt
            // capability. Local preparations must never become registrations.
            'registry' => ['status' => 'connection_pending', 'title' => 'assurance.registry.title',
                'message' => 'assurance.registry.connection_pending'],
        ];
    }
}
