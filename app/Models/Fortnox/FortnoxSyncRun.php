<?php

namespace App\Models\Fortnox;

use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Enums\Fortnox\FortnoxSyncTrigger;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $connection_id
 * @property FortnoxSyncTrigger $trigger_type
 * @property FortnoxSyncRunStatus $status
 * @property string $idempotency_key
 * @property string|null $dedupe_key
 * @property CarbonImmutable|null $watermark_before
 * @property CarbonImmutable|null $watermark_observed
 * @property int|null $cursor_page
 * @property bool $pagination_complete
 * @property int $attempts
 * @property int $articles_fetched
 * @property int $articles_created
 * @property int $articles_updated
 * @property int $articles_skipped
 * @property int $conflicts_raised
 * @property string|null $failure_message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $triggered_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read FortnoxConnection $connection
 * @property-read User|null $triggeredBy
 * @property-read Collection<int, FortnoxSyncConflict> $conflicts
 */
class FortnoxSyncRun extends Model
{
    use HasCompanyScope, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trigger_type' => FortnoxSyncTrigger::class,
            'status' => FortnoxSyncRunStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'watermark_before' => 'immutable_datetime',
            'watermark_observed' => 'immutable_datetime',
            'cursor_page' => 'integer',
            'pagination_complete' => 'boolean',
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

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(FortnoxSyncConflict::class, 'sync_run_id');
    }
}
