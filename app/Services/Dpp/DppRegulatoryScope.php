<?php

namespace App\Services\Dpp;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class DppRegulatoryScope
{
    /** @return array<string, mixed> */
    public function assess(mixed $context, CarbonImmutable $date): array
    {
        $result = [
            'status' => 'not_assessed', 'reason' => 'classification_missing',
            'checked_on' => $date->toDateString(), 'coverage' => 'scope_only',
            'source' => null, 'source_reference' => null, 'effective_from' => null,
            'registry_required' => null,
        ];
        if ($context === null) {
            return $result;
        }
        try {
            $facts = app(DppRegulatoryContext::class)->validate($context);
        } catch (ValidationException) {
            return array_replace($result, ['status' => 'invalid', 'reason' => 'classification_invalid']);
        }
        if ($facts['market'] !== 'EU') {
            return array_replace($result, ['status' => 'not_applicable', 'reason' => 'market_outside_eu', 'registry_required' => false]);
        }
        $type = $facts['product_type'];
        $battery = in_array($type, ['ev_battery', 'lmt_battery', 'industrial_battery', 'portable_battery'], true);
        if (! $battery) {
            // Electrical catalogue labels do not establish an ESPR delegated-act scope.
            return array_replace($result, ['status' => 'sector_assessment_required', 'reason' => 'sector_act_not_selected',
                'source' => 'EU 2024/1781', 'source_reference' => 'Articles 4 and 9']);
        }
        $covered = in_array($type, ['ev_battery', 'lmt_battery'], true)
            || ($type === 'industrial_battery' && $facts['battery_capacity_kwh'] > 2);
        $result = array_replace($result, [
            'source' => 'EU 2023/1542', 'source_reference' => 'Article 77(1), (2); Annex XIII',
            'effective_from' => '2027-02-18', 'required_passport_level' => $covered ? 'item' : null,
        ]);
        if (! $covered) {
            return array_replace($result, ['status' => 'not_applicable', 'reason' => 'battery_outside_article_77_scope', 'registry_required' => false]);
        }
        $mandatory = $date->toDateString() >= '2027-02-18';
        $eventDate = $facts['market_event_date'] ?? null;
        if (is_string($eventDate) && $eventDate < '2027-02-18') {
            return array_replace($result, ['status' => 'not_applicable', 'reason' => 'battery_event_before_deadline',
                'registry_required' => false,
                'market_event_date' => $eventDate, 'lifecycle_event' => $facts['lifecycle_event'] ?? 'initial_placement']);
        }
        if ($mandatory && $eventDate === null) {
            return array_replace($result, ['status' => 'assessment_incomplete', 'reason' => 'market_event_date_missing']);
        }

        return array_replace($result, [
            'status' => $mandatory ? 'mandatory' : 'upcoming',
            'reason' => 'battery_article_77', 'registry_required' => $mandatory,
            'item_identity_complete' => $facts['passport_level'] === 'item' && ! empty($facts['item_identifier']),
            'market_event_date' => $eventDate, 'lifecycle_event' => $facts['lifecycle_event'] ?? 'initial_placement',
        ]);
    }
}
