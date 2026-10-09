<?php

namespace App\Models;

use App\Models\Catalog\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $version
 * @property string|null $jurisdiction
 * @property string|null $product_scope
 * @property array<string, mixed>|null $metadata
 * @property string $status
 * @property CarbonInterface|null $effective_from
 * @property CarbonInterface|null $effective_to
 * @property-read Pivot $pivot
 */
class DppProfile extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'version', 'status', 'jurisdiction',
        'product_scope', 'effective_from', 'effective_to', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<DppRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(DppRequirement::class);
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_dpp_profiles')
            ->withPivot(['priority', 'assignment_reason'])
            ->withTimestamps();
    }
}
