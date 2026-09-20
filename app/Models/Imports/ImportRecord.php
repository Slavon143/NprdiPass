<?php

namespace App\Models\Imports;

use App\Enums\Imports\ImportRecordStatus;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Catalog\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $import_job_id
 * @property int $row_number
 * @property array<string, string|null> $source_data
 * @property array<string, mixed> $mapped_data
 * @property ImportRecordStatus $status
 * @property string|null $operation
 * @property int|null $target_product_id
 * @property CarbonImmutable|null $applied_at
 * @property-read Collection<int, ImportError> $errors
 */
class ImportRecord extends Model
{
    use HasCompanyScope;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => ImportRecordStatus::class, 'source_data' => 'array', 'mapped_data' => 'array', 'applied_at' => 'immutable_datetime'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }

    public function targetProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'target_product_id');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }
}
