<?php

namespace App\Models\Catalog;

use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductMedia extends Model
{
    use HasCompanyScope, HasUuid, SoftDeletes;

    protected $table = 'product_media';

    protected $fillable = ['alt_text', 'caption', 'sort_order'];

    protected $hidden = ['storage_path', 'checksum_sha256'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
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

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeProductLevel(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('product_variant_id'));
    }

    public function scopeVariantLevel(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('product_variant_id'));
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
