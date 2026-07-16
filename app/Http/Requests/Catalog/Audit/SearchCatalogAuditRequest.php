<?php

namespace App\Http\Requests\Catalog\Audit;

use App\Enums\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class SearchCatalogAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'event' => ['nullable', Rule::enum(AuditEvent::class)],
            'actor' => ['nullable', 'uuid'],
            'resource_type' => ['nullable', 'string', 'max:50'],
            'resource_uuid' => ['nullable', 'uuid'],
            'request_id' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', Rule::in(config('catalog.audit.per_page_options', [25, 50, 100]))],
            'sort' => ['nullable', Rule::in(['created_at', 'event'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $from = $this->input('date_from');
            $to = $this->input('date_to');

            if (! is_string($from) || ! is_string($to)) {
                return;
            }

            try {
                $fromDate = CarbonImmutable::parse($from);
                $toDate = CarbonImmutable::parse($to);
            } catch (Throwable) {
                return;
            }

            if ($fromDate->isAfter($toDate)) {
                $validator->errors()->add('date_to', 'The end date must be on or after the start date.');

                return;
            }

            $maxDays = config('catalog.audit.max_date_range_days', 366);

            if ($fromDate->diffInDays($toDate) > $maxDays) {
                $validator->errors()->add('date_to', "The audit date range cannot exceed {$maxDays} days.");
            }
        }];
    }
}
