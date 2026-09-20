<?php

namespace App\Models\Fortnox;

use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $connection_id
 * @property string $fortnox_article_number
 * @property string|null $fortnox_article_id
 * @property int|null $product_id
 * @property int|null $variant_id
 * @property CarbonImmutable|null $fortnox_last_modified
 * @property array<string, mixed>|null $last_applied_values
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read FortnoxConnection $connection
 * @property-read Product|null $product
 * @property-read ProductVariant|null $variant
 */
class FortnoxArticleMapping extends Model
{
    use HasCompanyScope, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fortnox_last_modified' => 'immutable_datetime',
            'last_applied_values' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(FortnoxConnection::class, 'connection_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
