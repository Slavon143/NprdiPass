<?php

namespace App\Models\Imports;

use App\Models\Catalog\Concerns\HasCompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $import_job_id
 * @property int|null $import_record_id
 * @property int $row_number
 * @property string|null $field
 * @property string $code
 * @property string $message
 */
class ImportError extends Model
{
    use HasCompanyScope;

    protected $guarded = ['id'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(ImportRecord::class, 'import_record_id');
    }
}
