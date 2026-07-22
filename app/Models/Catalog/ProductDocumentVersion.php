<?php

namespace App\Models\Catalog;

use App\Enums\Documents\ProductDocumentApprovalStatus;
use App\Enums\Documents\ProductDocumentExpiryState;
use App\Enums\Documents\ProductDocumentReviewStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Catalog\ProductDocumentVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $document_id
 * @property int $version_number
 * @property ProductDocumentType $document_type
 * @property string $title
 * @property string|null $description
 * @property string $language
 * @property ProductDocumentVisibility $visibility
 * @property array<string, mixed>|null $metadata
 * @property ProductDocumentReviewStatus $review_status
 * @property ProductDocumentApprovalStatus $approval_status
 * @property string|null $issuer_name
 * @property string|null $certificate_number
 * @property string|null $issuing_body
 * @property string|null $declaration_identifier
 * @property string|null $evidence_type
 * @property string|null $topic_code
 * @property string|null $standard_reference
 * @property string|null $applicable_market
 * @property string|null $reference_url
 * @property CarbonImmutable|null $issue_date
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property CarbonImmutable|null $expires_at
 * @property string $original_filename
 * @property string|null $safe_display_filename
 * @property string $mime_type
 * @property string $file_extension
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property string $storage_key
 * @property bool $file_available
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $submitted_by_user_id
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by_user_id
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by_user_id
 * @property string|null $review_comment
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $published_at
 * @property int $published_snapshot_count
 * @property int $created_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read ProductDocument $document
 * @property-read User $creator
 */
class ProductDocumentVersion extends Model
{
    use HasCompanyScope, HasUuid;

    /** @use HasFactory<ProductDocumentVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'document_type',
        'title',
        'description',
        'language',
        'visibility',
        'metadata',
        'issuer_name',
        'certificate_number',
        'issuing_body',
        'declaration_identifier',
        'evidence_type',
        'topic_code',
        'standard_reference',
        'applicable_market',
        'reference_url',
        'issue_date',
        'valid_from',
        'valid_until',
        'expires_at',
        'original_filename',
        'safe_display_filename',
        'mime_type',
        'file_extension',
        'size_bytes',
        'checksum_sha256',
        'storage_key',
    ];

    protected $hidden = [
        'id',
        'company_id',
        'document_id',
        'created_by_user_id',
        'storage_key',
        'checksum_sha256',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => ProductDocumentType::class,
            'visibility' => ProductDocumentVisibility::class,
            'metadata' => 'array',
            'review_status' => ProductDocumentReviewStatus::class,
            'approval_status' => ProductDocumentApprovalStatus::class,
            'version_number' => 'integer',
            'size_bytes' => 'integer',
            'issue_date' => 'immutable_date',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'expires_at' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'file_available' => 'boolean',
            'published_at' => 'immutable_datetime',
            'published_snapshot_count' => 'integer',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isExpired(): bool
    {
        $validUntil = $this->valid_until ?? $this->expires_at;

        if ($validUntil === null) {
            return false;
        }

        return $validUntil->isBefore(now()->startOfDay());
    }

    public function expiresSoon(?int $days = null): bool
    {
        $validUntil = $this->valid_until ?? $this->expires_at;

        if ($validUntil === null) {
            return false;
        }

        $days ??= (int) config('documents.expiry_warning_days', 30);
        $today = now()->startOfDay();
        $deadline = $today->copy()->addDays($days);

        return $validUntil->lte($deadline) && $validUntil->gte($today);
    }

    public function expiryState(?CarbonImmutable $evaluationDate = null, ?int $warningDays = null): ProductDocumentExpiryState
    {
        if (! $this->document_type->supportsExpiry()) {
            return ProductDocumentExpiryState::NotApplicable;
        }

        $validFrom = $this->valid_from ?? $this->issue_date;
        $validUntil = $this->valid_until ?? $this->expires_at;

        if ($validFrom === null && $validUntil === null) {
            return ProductDocumentExpiryState::Unknown;
        }

        $today = ($evaluationDate ?? CarbonImmutable::now())->startOfDay();

        if ($validFrom !== null && $validFrom->isAfter($today)) {
            return ProductDocumentExpiryState::NotYetValid;
        }

        if ($validUntil !== null && $validUntil->isBefore($today)) {
            return ProductDocumentExpiryState::Expired;
        }

        $warningDays ??= (int) config('documents.expiry_warning_days', 30);

        if ($validUntil !== null && $validUntil->lte($today->addDays($warningDays))) {
            return ProductDocumentExpiryState::ExpiringSoon;
        }

        return ProductDocumentExpiryState::Valid;
    }

    public function isApproved(): bool
    {
        return $this->review_status === ProductDocumentReviewStatus::Approved
            && $this->approval_status === ProductDocumentApprovalStatus::Approved;
    }

    public function isPublishable(?CarbonImmutable $evaluationDate = null): bool
    {
        return $this->isApproved()
            && ($this->file_available ?? true)
            && $this->visibility === ProductDocumentVisibility::PassportPublic
            && ! in_array($this->expiryState($evaluationDate), [
                ProductDocumentExpiryState::Expired,
                ProductDocumentExpiryState::NotYetValid,
                ProductDocumentExpiryState::Unknown,
            ], true);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('expires_at'))
            ->where($query->qualifyColumn('expires_at'), '<', now()->startOfDay());
    }

    public function scopeExpiringWithinDays(Builder $query, int $days): Builder
    {
        $now = now()->startOfDay();

        return $query->whereNotNull($query->qualifyColumn('expires_at'))
            ->where($query->qualifyColumn('expires_at'), '>=', $now)
            ->where($query->qualifyColumn('expires_at'), '<=', $now->copy()->addDays($days));
    }

    public function scopeByType(Builder $query, ProductDocumentType $type): Builder
    {
        return $query->where($query->qualifyColumn('document_type'), $type->value);
    }

    public function scopeByVisibility(Builder $query, ProductDocumentVisibility $visibility): Builder
    {
        return $query->where($query->qualifyColumn('visibility'), $visibility->value);
    }

    public function scopeByLanguage(Builder $query, string $language): Builder
    {
        return $query->where($query->qualifyColumn('language'), $language);
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $allowed = [
                'review_status',
                'approval_status',
                'submitted_at',
                'submitted_by_user_id',
                'reviewed_at',
                'reviewed_by_user_id',
                'approved_at',
                'approved_by_user_id',
                'review_comment',
                'rejection_reason',
                'file_available',
                'published_at',
                'published_snapshot_count',
                'updated_at',
            ];

            if (array_diff(array_keys($version->getDirty()), $allowed) !== []) {
                throw new \RuntimeException('product_document_versions content is immutable.');
            }
        });

        static::deleting(function (self $version): never {
            throw new \RuntimeException('product_document_versions cannot be deleted.');
        });
    }
}
