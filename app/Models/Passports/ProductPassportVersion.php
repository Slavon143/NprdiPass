<?php

namespace App\Models\Passports;

use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Passports\ProductPassportVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $passport_id
 * @property ProductPassportVersionStatus $status
 * @property int|null $version_number
 * @property int $draft_revision
 * @property string $schema_version
 * @property array $payload
 * @property string|null $content_checksum
 * @property int|null $validation_run_id
 * @property array|null $readiness_evidence
 * @property CarbonImmutable|null $published_at
 * @property int|null $published_by
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable|null $withdrawn_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read ProductPassport $passport
 * @property-read Collection<ProductPassportAsset> $assets
 * @property-read User|null $publisher
 * @property-read User|null $creator
 * @property-read User|null $updater
 * @property-read PassportValidationRun|null $validationRun
 */
class ProductPassportVersion extends Model
{
    /** @use HasFactory<ProductPassportVersionFactory> */
    use HasFactory, HasUuid;

    protected $table = 'product_passport_versions';

    protected $fillable = [
        'payload',
        'draft_revision',
        'schema_version',
    ];

    protected $hidden = [
        'id',
        'company_id',
        'passport_id',
        'content_checksum',
        'created_by',
        'updated_by',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductPassportVersionStatus::class,
            'version_number' => 'integer',
            'draft_revision' => 'integer',
            'payload' => 'array',
            'readiness_evidence' => 'array',
            'published_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
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

    public function assets(): HasMany
    {
        return $this->hasMany(ProductPassportAsset::class, 'version_id');
    }

    public function validationRun(): BelongsTo
    {
        return $this->belongsTo(PassportValidationRun::class, 'validation_run_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where(
            $query->qualifyColumn('company_id'),
            $company instanceof Company ? $company->id : $company,
        );
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ProductPassportVersionStatus::Draft->value);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ProductPassportVersionStatus::Published->value);
    }

    public function isDraft(): bool
    {
        return $this->status === ProductPassportVersionStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status === ProductPassportVersionStatus::Published;
    }

    public function isSuperseded(): bool
    {
        return $this->status === ProductPassportVersionStatus::Superseded;
    }

    public function isWithdrawn(): bool
    {
        return $this->status === ProductPassportVersionStatus::Withdrawn;
    }

    public function isImmutable(): bool
    {
        return ! $this->isDraft();
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $original = $version->getOriginal('status');

            if ($original === ProductPassportVersionStatus::Superseded->value
                || $original === ProductPassportVersionStatus::Withdrawn->value) {
                throw new \RuntimeException('Superseded and withdrawn passport versions are immutable.');
            }

            if ($original === ProductPassportVersionStatus::Published->value) {
                $newStatus = $version->status->value ?? $original;

                if ($newStatus === ProductPassportVersionStatus::Superseded->value) {
                    $allowed = ['status', 'superseded_at', 'updated_at'];
                    $dirty = array_keys($version->getDirty());
                    $forbidden = array_diff($dirty, $allowed);

                    if ($forbidden !== []) {
                        throw new \RuntimeException('Only status and superseded_at may change when superseding a published version. Forbidden: '.implode(', ', $forbidden));
                    }
                } elseif ($newStatus === ProductPassportVersionStatus::Withdrawn->value) {
                    $allowed = ['status', 'withdrawn_at', 'updated_at'];
                    $dirty = array_keys($version->getDirty());
                    $forbidden = array_diff($dirty, $allowed);

                    if ($forbidden !== []) {
                        throw new \RuntimeException('Only status and withdrawn_at may change when withdrawing a published version. Forbidden: '.implode(', ', $forbidden));
                    }
                } else {
                    throw new \RuntimeException('Published versions may only transition to superseded or withdrawn.');
                }
            }
        });

        static::deleting(function (self $version): void {
            if ($version->getOriginal('status') !== ProductPassportVersionStatus::Draft->value) {
                throw new \RuntimeException('Published, superseded, and withdrawn passport versions cannot be deleted.');
            }
        });
    }
}
