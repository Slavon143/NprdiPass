<?php

namespace App\Models\Catalog;

use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryProduct extends Model
{
    use HasCompanyScope;

    protected $table = 'category_product';

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
