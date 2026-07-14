<?php

namespace App\Models\Catalog;

use App\Enums\Catalog\ProductVariantStatus;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $product_id
 * @property int|null $primary_media_id
 * @property string|null $name
 * @property string|null $sku
 * @property string|null $gtin
 * @property string|null $mpn
 * @property ProductVariantStatus $status
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Product $product
 * @property-read Collection<int, VariantAttributeValue> $attributeValues
 */
class ProductVariant extends Model
{
    use HasCompanyScope, HasUuid, SoftDeletes;

    protected $fillable = ['name', 'sku', 'gtin', 'mpn', 'sort_order'];

    protected $hidden = ['sku_normalized', 'is_default'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'status' => ProductVariantStatus::class,
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

    public function attributeValues(): HasMany
    {
        return $this->hasMany(VariantAttributeValue::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
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

    public function isDefaultFor(Product $product): bool
    {
        return $product->getAttribute('default_variant_id') === $this->getKey();
    }

    public function displayName(): string
    {
        $name = trim((string) $this->getAttribute('name'));

        if ($name !== '') {
            return $name;
        }

        $sku = trim((string) $this->getAttribute('sku'));

        if ($sku !== '') {
            return $sku;
        }

        $uuid = (string) $this->getAttribute('uuid');

        return $uuid === '' ? 'Variant' : substr($uuid, 0, 8);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ProductVariantStatus::Active->value);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ProductVariantStatus::Archived->value);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
