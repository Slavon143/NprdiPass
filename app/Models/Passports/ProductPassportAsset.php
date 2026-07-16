<?php

namespace App\Models\Passports;

use App\Enums\Passports\ProductPassportAssetKind;
use App\Models\Company;
use App\Models\Concerns\HasUuid;
use Database\Factories\Passports\ProductPassportAssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $passport_id
 * @property int $version_id
 * @property ProductPassportAssetKind $kind
 * @property string|null $source_resource_uuid
 * @property string|null $role
 * @property int $sort_order
 * @property string|null $language
 * @property string $mime_type
 * @property string $file_extension
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string $checksum_sha256
 * @property string $storage_key
 * @property bool $is_public
 * @property-read Company $company
 * @property-read ProductPassport $passport
 * @property-read ProductPassportVersion $version
 */
class ProductPassportAsset extends Model
{
    /** @use HasFactory<ProductPassportAssetFactory> */
    use HasFactory, HasUuid;

    protected $table = 'product_passport_assets';

    protected $fillable = [
        'kind',
        'source_resource_uuid',
        'role',
        'sort_order',
        'language',
        'mime_type',
        'file_extension',
        'size_bytes',
        'width',
        'height',
        'checksum_sha256',
        'storage_key',
        'is_public',
    ];

    protected $hidden = [
        'id',
        'company_id',
        'passport_id',
        'version_id',
        'storage_key',
        'source_resource_uuid',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ProductPassportAssetKind::class,
            'sort_order' => 'integer',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function passport(): BelongsTo
    {
        return $this->belongsTo(ProductPassport::class, 'passport_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductPassportVersion::class, 'version_id');
    }

    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where(
            $query->qualifyColumn('company_id'),
            $company instanceof Company ? $company->id : $company,
        );
    }
}
