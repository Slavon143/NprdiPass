<?php

namespace App\Models\Passports;

use App\Models\Company;
use App\Models\Concerns\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $product_passport_id
 * @property string $idempotency_key
 * @property string $request_fingerprint
 * @property string $operation
 * @property string $status
 * @property int|null $published_version_id
 * @property int|null $response_code
 * @property array|null $response_payload
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read ProductPassport $productPassport
 * @property-read ProductPassportVersion|null $publishedVersion
 */
class PublicationIdempotencyRecord extends Model
{
    use HasUuid;

    protected $table = 'publication_idempotency_records';

    protected $fillable = [
        'idempotency_key',
        'request_fingerprint',
        'operation',
        'status',
        'published_version_id',
        'response_code',
        'response_payload',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected $hidden = [
        'id',
        'company_id',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function productPassport(): BelongsTo
    {
        return $this->belongsTo(ProductPassport::class, 'product_passport_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ProductPassportVersion::class, 'published_version_id');
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
