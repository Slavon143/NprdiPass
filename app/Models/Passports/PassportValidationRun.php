<?php

namespace App\Models\Passports;

use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $passport_id
 * @property int $draft_version_id
 * @property array $weights_snapshot
 * @property int $earned_points
 * @property int $applicable_points
 * @property int $score
 * @property CarbonImmutable $validated_at
 * @property-read Collection<PassportValidationResult> $results
 */
class PassportValidationRun extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'company_id', 'passport_id', 'draft_version_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'weights_snapshot' => 'array',
            'validated_at' => 'immutable_datetime',
            'profile_version' => 'integer',
            'rule_set_version' => 'integer',
            'score_algorithm_version' => 'integer',
            'earned_points' => 'integer',
            'applicable_points' => 'integer',
            'score' => 'integer',
            'draft_revision' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function passport(): BelongsTo
    {
        return $this->belongsTo(ProductPassport::class, 'passport_id');
    }

    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(ProductPassportVersion::class, 'draft_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(PassportValidationResult::class, 'validation_run_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('Passport validation runs are immutable.'));
        static::deleting(fn () => throw new \RuntimeException('Passport validation runs are immutable.'));
    }
}
