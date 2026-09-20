<?php

namespace App\Models\Fortnox;

use App\Enums\Fortnox\FortnoxConflictStatus;
use App\Enums\Fortnox\FortnoxField;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $sync_run_id
 * @property int $mapping_id
 * @property FortnoxField $field
 * @property array<string, mixed>|null $fortnox_value
 * @property array<string, mixed>|null $nordipass_value
 * @property FortnoxConflictStatus $status
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read FortnoxSyncRun $syncRun
 * @property-read FortnoxArticleMapping $mapping
 * @property-read User|null $resolvedBy
 */
class FortnoxSyncConflict extends Model
{
    use HasCompanyScope, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'field' => FortnoxField::class,
            'fortnox_value' => 'array',
            'nordipass_value' => 'array',
            'status' => FortnoxConflictStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(FortnoxSyncRun::class, 'sync_run_id');
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(FortnoxArticleMapping::class, 'mapping_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
