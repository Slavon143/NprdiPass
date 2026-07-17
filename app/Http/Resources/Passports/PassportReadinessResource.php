<?php

namespace App\Http\Resources\Passports;

use App\Data\Passports\Readiness\PassportReadinessResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PassportReadinessResult */
class PassportReadinessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'profile' => $this->profile,
            'profile_version' => $this->profileVersion,
            'schema_version' => $this->schemaVersion,
            'passport_uuid' => $this->passportUuid,
            'draft_version_uuid' => $this->draftVersionUuid,
            'passport_revision' => $this->passportRevision,
            'status' => $this->status->value,
            'score' => $this->score,
            'counts' => [
                'passed' => $this->counts->passed,
                'blockers' => $this->counts->blockers,
                'warnings' => $this->counts->warnings,
                'recommendations' => $this->counts->recommendations,
                'not_applicable' => $this->counts->notApplicable,
            ],
            'rules' => ReadinessRuleResource::collection($this->rules),
            'evaluated_at' => $this->evaluatedAt->toISOString(),
        ];
    }
}
