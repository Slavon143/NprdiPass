<?php

namespace App\Models\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int|null $primary_category_id
 * @property int $default_variant_id
 * @property int|null $primary_media_id
 * @property string $name
 * @property string $slug
 * @property string|null $short_description
 * @property string|null $description
 * @property string|null $brand
 * @property string|null $manufacturer
 * @property ProductStatus $status
 * @property CarbonImmutable|null $published_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Category|null $primaryCategory
 * @property ProductVariant|null $defaultVariant
 * @property-read Collection<int, Category> $categories
 */
class Product extends Model
{
    use HasCompanyScope, HasUuid, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'short_description',
        'description',
        'brand',
        'manufacturer',
    ];

    protected $hidden = ['slug_normalized'];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function defaultVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'default_variant_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)
            ->withPivot(['id', 'company_id', 'created_at']);
    }

    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
    }

    public function productMedia(): HasMany
    {
        return $this->media()->whereNull('product_variant_id');
    }

    public function primaryMedia(): BelongsTo
    {
        return $this->belongsTo(ProductMedia::class, 'primary_media_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ProductStatus::Draft->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ProductStatus::Active->value);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ProductStatus::Archived->value);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
