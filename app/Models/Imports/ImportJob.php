<?php

namespace App\Models\Imports;

use App\Enums\Imports\ImportJobStatus;
use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportNumberFormat;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property ImportMode $mode
 * @property ImportJobStatus $status
 * @property string $source_format
 * @property string $source_filename
 * @property string $source_path
 * @property string $source_sha256
 * @property int $source_size
 * @property array<int, string>|null $headers
 * @property array<string, string>|null $mapping
 * @property ImportNumberFormat $number_format
 * @property int $chunk_size
 * @property int $next_row
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $invalid_rows
 * @property int $processed_rows
 * @property int $created_rows
 * @property int $updated_rows
 * @property int $skipped_rows
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property Carbon|null $created_at
 * @property-read Collection<int, ImportRecord> $records
 */
class ImportJob extends Model
{
    use HasCompanyScope, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ImportJobStatus::class,
            'mode' => ImportMode::class,
            'headers' => 'array',
            'mapping' => 'array',
            'number_format' => ImportNumberFormat::class,
            'previewed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ImportMappingTemplate::class, 'mapping_template_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(ImportRecord::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }
}
