<?php

namespace App\Models\Passports;

use App\Models\Company;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PassportValidationResult extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'company_id', 'validation_run_id'];

    protected function casts(): array
    {
        return [
            'configured_weight' => 'integer',
            'fix_action_snapshot' => 'array',
            'safe_context' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function validationRun(): BelongsTo
    {
        return $this->belongsTo(PassportValidationRun::class, 'validation_run_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('Passport validation results are immutable.'));
        static::deleting(fn () => throw new \RuntimeException('Passport validation results are immutable.'));
    }
}
