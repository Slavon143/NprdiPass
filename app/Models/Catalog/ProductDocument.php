<?php

namespace App\Models\Catalog;

use App\Enums\Documents\ProductDocumentStatus;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $product_id
 * @property ProductDocumentStatus $status
 * @property int|null $current_version_id
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read Product $product
 * @property-read ProductDocumentVersion|null $currentVersion
 * @property-read Collection<int, ProductDocumentVersion> $versions
 * @property-read User $creator
 * @property-read User|null $updater
 */
class ProductDocument extends Model
{
    use HasCompanyScope, HasUuid;

    protected $fillable = ['status'];

    protected $hidden = [
        'id',
        'company_id',
        'product_id',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductDocumentStatus::class,
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProductDocumentVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProductDocumentVersion::class, 'document_id')->orderBy('version_number');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ProductDocumentStatus::Active->value);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ProductDocumentStatus::Archived->value);
    }

    public function isActive(): bool
    {
        return $this->status === ProductDocumentStatus::Active;
    }

    public function isArchived(): bool
    {
        return $this->status === ProductDocumentStatus::Archived;
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            if ($document->isDirty('uuid')) {
                throw new \RuntimeException('product_documents.uuid is immutable.');
            }
            if ($document->isDirty('company_id')) {
                throw new \RuntimeException('product_documents.company_id is immutable.');
            }
            if ($document->isDirty('product_id')) {
                throw new \RuntimeException('product_documents.product_id is immutable.');
            }
        });
    }
}
