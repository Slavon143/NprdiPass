<?php

namespace App\Http\Resources\Passports;

use App\Data\Passports\Readiness\ReadinessRuleResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReadinessRuleResult */
class ReadinessRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'group' => $this->group->value,
            'severity' => $this->severity->value,
            'status' => $this->status->value,
            'title_key' => $this->titleKey,
            'message_key' => $this->messageKey,
            'section' => $this->section?->value,
            'field' => $this->field,
            'navigation_target' => $this->when($this->navigationTarget !== null, fn () => [
                'type' => $this->navigationTarget->type,
                'section' => $this->navigationTarget->section,
                'route_name' => $this->navigationTarget->routeName,
                'route_parameters' => $this->navigationTarget->routeParameters,
                'label' => $this->navigationTarget->label,
            ]),
            'safe_context' => $this->safeContext,
        ];
    }
}
