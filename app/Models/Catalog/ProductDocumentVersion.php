<?php

namespace App\Models\Catalog;

use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\Catalog\Concerns\HasCompanyScope;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $issuer_name
 * @property CarbonImmutable|null $issue_date
 * @property CarbonImmutable|null $expires_at
 * @property string $original_filename
 * @property string $mime_type
 * @property string $file_extension
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property string $storage_key
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

    protected $fillable = [
        'document_type',
        'title',
        'description',
        'language',
        'visibility',
        'issuer_name',
        'issue_date',
        'expires_at',
        'original_filename',
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
            'version_number' => 'integer',
            'size_bytes' => 'integer',
            'issue_date' => 'immutable_date',
            'expires_at' => 'immutable_date',
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
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isBefore(now()->startOfDay());
    }

    public function expiresSoon(?int $days = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        $days ??= (int) config('documents.expiry_warning_days', 30);
        $today = now()->startOfDay();
        $deadline = $today->copy()->addDays($days);

        return $this->expires_at->lte($deadline) && $this->expires_at->gte($today);
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
        static::updating(function (self $version): never {
            throw new \RuntimeException('product_document_versions are immutable.');
        });

        static::deleting(function (self $version): never {
            throw new \RuntimeException('product_document_versions cannot be deleted.');
        });
    }
}
