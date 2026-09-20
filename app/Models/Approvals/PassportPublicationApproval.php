<?php

namespace App\Models\Approvals;

use App\Enums\Approvals\PassportPublicationApprovalStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $product_id
 * @property int $passport_id
 * @property int $draft_version_id
 * @property int $expected_revision
 * @property PassportPublicationApprovalStatus $status
 * @property bool $acknowledge_warnings
 * @property int $requested_by
 * @property CarbonImmutable $requested_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $published_version_id
 * @property string|null $request_note
 */
class PassportPublicationApproval extends Model
{
    use HasUuid;

    protected $fillable = [
        'company_id',
        'product_id',
        'passport_id',
        'draft_version_id',
        'expected_revision',
        'status',
        'acknowledge_warnings',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'published_version_id',
        'request_note',
    ];

    protected function casts(): array
    {
        return [
            'expected_revision' => 'integer',
            'status' => PassportPublicationApprovalStatus::class,
            'acknowledge_warnings' => 'boolean',
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function passport(): BelongsTo
    {
        return $this->belongsTo(ProductPassport::class, 'passport_id');
    }

    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(ProductPassportVersion::class, 'draft_version_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ProductPassportVersion::class, 'published_version_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), PassportPublicationApprovalStatus::Pending->value);
    }

    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where(
            $query->qualifyColumn('company_id'),
            $company instanceof Company ? $company->id : $company,
        );
    }
}
