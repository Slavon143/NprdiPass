<?php

namespace App\Models\Catalog;

use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $document_id
 * @property int $version_id
 * @property int $actor_id
 * @property string $decision
 * @property string|null $previous_review_status
 * @property string $new_review_status
 * @property string|null $previous_approval_status
 * @property string $new_approval_status
 * @property string|null $comment
 * @property CarbonImmutable $decided_at
 */
class ProductDocumentReviewDecision extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ProductDocument::class, 'document_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductDocumentVersion::class, 'version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
