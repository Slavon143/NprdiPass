<?php

namespace App\Services\Dpp;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DppRegulatoryContext
{
    public const TYPES = ['electrical', 'ev_battery', 'lmt_battery', 'industrial_battery', 'portable_battery', 'other'];

    /** @return array<string, mixed> */
    public function validate(mixed $context): array
    {
        $validated = Validator::make(['regulatory_context' => $context], [
            'regulatory_context' => ['required', 'array:market,product_type,battery_capacity_kwh,passport_level,model_identifier,item_identifier,assessment_source,lifecycle_event,market_event_date'],
            'regulatory_context.market' => ['required', Rule::in(['EU', 'outside_EU'])],
            'regulatory_context.product_type' => ['required', Rule::in(self::TYPES)],
            'regulatory_context.battery_capacity_kwh' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'regulatory_context.passport_level' => ['required', Rule::in(['model', 'batch', 'item'])],
            'regulatory_context.model_identifier' => ['nullable', 'string', 'max:200'],
            'regulatory_context.item_identifier' => ['nullable', 'string', 'max:200'],
            'regulatory_context.assessment_source' => ['required', 'string', 'max:1000'],
            'regulatory_context.lifecycle_event' => ['sometimes', 'required', Rule::in(['initial_placement', 'putting_into_service', 'remanufacturing', 'repurposing'])],
            'regulatory_context.market_event_date' => ['nullable', 'date_format:Y-m-d'],
        ])->after(function ($validator) use ($context): void {
            if (! is_array($context)) {
                return;
            }
            if (($context['product_type'] ?? null) === 'industrial_battery' && empty($context['battery_capacity_kwh'])) {
                $validator->errors()->add('regulatory_context.battery_capacity_kwh', __('dpp.capacity_required'));
            }
            if (is_string($context['assessment_source'] ?? null) && trim($context['assessment_source']) === '') {
                $validator->errors()->add('regulatory_context.assessment_source', __('dpp.source_required'));
            }
        })->validate()['regulatory_context'];

        foreach (['model_identifier', 'item_identifier', 'assessment_source'] as $field) {
            if (is_string($validated[$field] ?? null)) {
                $validated[$field] = trim($validated[$field]);
            }
        }
        if (($validated['battery_capacity_kwh'] ?? null) === '') {
            $validated['battery_capacity_kwh'] = null;
        } elseif (isset($validated['battery_capacity_kwh'])) {
            $validated['battery_capacity_kwh'] = (float) $validated['battery_capacity_kwh'];
        }
        if (($validated['market_event_date'] ?? null) === '') {
            $validated['market_event_date'] = null;
        }

        return $validated;
    }
}
