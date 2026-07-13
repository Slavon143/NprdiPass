<?php

namespace App\Http\Requests;

use App\Enums\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class AuditLogIndexRequest extends FormRequest
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
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
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

            if ($fromDate->diffInDays($toDate) > 366) {
                $validator->errors()->add('date_to', 'The audit date range cannot exceed 366 days.');
            }
        }];
    }
}
