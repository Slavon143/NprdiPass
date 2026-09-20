<?php

namespace App\Models\Analytics;

use App\Enums\Analytics\AnalyticsDeviceCategory;
use App\Enums\Analytics\AnalyticsEventType;
use App\Enums\Analytics\AnalyticsReferrerCategory;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassportVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $product_id
 * @property int|null $published_version_id
 * @property string $passport_public_id
 * @property AnalyticsEventType $event_type
 * @property string $country_code
 * @property AnalyticsDeviceCategory $device_category
 * @property AnalyticsReferrerCategory $referrer_category
 * @property bool $bot_flag
 * @property CarbonImmutable $occurred_at
 */
class AnalyticsEvent extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'published_version_id',
        'passport_public_id',
        'event_type',
        'country_code',
        'device_category',
        'referrer_category',
        'bot_flag',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AnalyticsEventType::class,
            'device_category' => AnalyticsDeviceCategory::class,
            'referrer_category' => AnalyticsReferrerCategory::class,
            'bot_flag' => 'boolean',
            'occurred_at' => 'immutable_datetime',
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

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ProductPassportVersion::class, 'published_version_id');
    }

    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where(
            $query->qualifyColumn('company_id'),
            $company instanceof Company ? $company->id : $company,
        );
    }
}
