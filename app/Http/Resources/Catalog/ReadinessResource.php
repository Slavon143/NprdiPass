<?php

namespace App\Http\Resources\Catalog;

use App\Data\Catalog\Lifecycle\ProductActivationReadiness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductActivationReadiness
 */
class ReadinessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ready' => $this->ready,
            'blockers' => array_map(
                fn ($item) => [
                    'code' => $item->code,
                    'message' => $item->message,
                    'section' => $item->section,
                    'entity_type' => $item->entityType,
                    'entity_uuid' => $item->entityUuid,
                ],
                $this->blockers,
            ),
            'warnings' => array_map(
                fn ($item) => [
                    'code' => $item->code,
                    'message' => $item->message,
                    'section' => $item->section,
                    'entity_type' => $item->entityType,
                    'entity_uuid' => $item->entityUuid,
                ],
                $this->warnings,
            ),
            'checked_at' => $this->checkedAt->toIso8601String(),
        ];
    }
}
