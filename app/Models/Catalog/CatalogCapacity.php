<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int|null $products_count
 * @property int|null $total_products_count
 */
class CatalogCapacity extends Model
{
    protected $table = 'catalog_product_capacities';

    protected $primaryKey = 'company_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['products_count', 'total_products_count'];

    protected function casts(): array
    {
        return ['products_count' => 'integer', 'total_products_count' => 'integer'];
    }
}
