<?php

namespace App\Models\Passports;

use App\Enums\Passports\ProductPassportStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Passports\ProductPassportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ramsey\Uuid\Uuid;

/**
 * @property int $id
 * @property string $uuid
 * @property string $public_id
 * @property int $company_id
 * @property int $product_id
 * @property ProductPassportStatus $status
 * @property string $default_language
 * @property array $enabled_languages
 * @property int|null $current_draft_version_id
 * @property int|null $current_published_version_id
 * @property CarbonImmutable|null $first_published_at
 * @property CarbonImmutable|null $last_published_at
 * @property CarbonImmutable|null $unpublished_at
 * @property CarbonImmutable|null $archived_at
 * @property int $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read Product $product
 * @property-read Collection<ProductPassportVersion> $versions
 * @property-read ProductPassportVersion|null $currentDraftVersion
 * @property-read ProductPassportVersion|null $currentPublishedVersion
 * @property-read Collection<ProductPassportAsset> $assets
 * @property-read Collection<PassportValidationRun> $validationRuns
 * @property-read User $creator
 * @property-read User|null $updater
 */
class ProductPassport extends Model
{
    /** @use HasFactory<ProductPassportFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'default_language',
        'enabled_languages',
    ];

    protected $hidden = [
        'id',
        'company_id',
        'product_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductPassportStatus::class,
            'first_published_at' => 'immutable_datetime',
            'last_published_at' => 'immutable_datetime',
            'unpublished_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * Keep the domain value array-shaped even when reading legacy rows that
     * accidentally stored a JSON-encoded string inside the JSON column.
     */
    protected function enabledLanguages(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): array {
                $decoded = is_string($value) ? json_decode($value, true) : $value;

                if (is_string($decoded)) {
                    $decoded = json_decode($decoded, true);
                }

                if (! is_array($decoded)) {
                    return [];
                }

                return array_values(array_filter($decoded, 'is_string'));
            },
            set: fn (mixed $value): string => json_encode(
                is_array($value) ? array_values($value) : [],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProductPassportVersion::class, 'passport_id');
    }

    public function currentDraftVersion(): BelongsTo
    {
        return $this->belongsTo(ProductPassportVersion::class, 'current_draft_version_id');
    }

    public function currentPublishedVersion(): BelongsTo
    {
        return $this->belongsTo(ProductPassportVersion::class, 'current_published_version_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ProductPassportAsset::class, 'passport_id');
    }

    public function validationRuns(): HasMany
    {
        return $this->hasMany(PassportValidationRun::class, 'passport_id');
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
        return $query->where($query->qualifyColumn('status'), ProductPassportStatus::Draft->value);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ProductPassportStatus::Published->value);
    }

    public function isDraft(): bool
    {
        return $this->status === ProductPassportStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status === ProductPassportStatus::Published;
    }

    public function isUnpublished(): bool
    {
        return $this->status === ProductPassportStatus::Unpublished;
    }

    public function isArchived(): bool
    {
        return $this->status === ProductPassportStatus::Archived;
    }

    public function hasPublishedVersion(): bool
    {
        return $this->current_published_version_id !== null;
    }

    protected static function booted(): void
    {
        static::creating(function (self $passport): void {
            if (empty($passport->getAttribute('public_id'))) {
                $passport->setAttribute('public_id', Uuid::uuid7()->toString());
            }
        });

        static::updating(function (self $passport): void {
            if ($passport->isDirty('uuid')) {
                throw new \RuntimeException('product_passports.uuid is immutable.');
            }
            if ($passport->isDirty('public_id')) {
                throw new \RuntimeException('product_passports.public_id is immutable.');
            }
            if ($passport->isDirty('company_id')) {
                throw new \RuntimeException('product_passports.company_id is immutable.');
            }
            if ($passport->isDirty('product_id')) {
                throw new \RuntimeException('product_passports.product_id is immutable.');
            }
        });
    }
}
