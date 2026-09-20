<?php

namespace App\Models\Analytics;

use App\Enums\Analytics\AnalyticsDeviceCategory;
use App\Enums\Analytics\AnalyticsEventType;
use App\Enums\Analytics\AnalyticsReferrerCategory;
use App\Models\Catalog\Product;
use App\Models\Company;
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
 * @property CarbonImmutable $aggregate_date
 * @property string $country_code
 * @property AnalyticsDeviceCategory $device_category
 * @property AnalyticsReferrerCategory $referrer_category
 * @property int $event_count
 */
class AnalyticsDailyAggregate extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'published_version_id',
        'passport_public_id',
        'event_type',
        'aggregate_date',
        'country_code',
        'device_category',
        'referrer_category',
        'event_count',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AnalyticsEventType::class,
            'aggregate_date' => 'immutable_date',
            'device_category' => AnalyticsDeviceCategory::class,
            'referrer_category' => AnalyticsReferrerCategory::class,
            'event_count' => 'integer',
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

    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where(
            $query->qualifyColumn('company_id'),
            $company instanceof Company ? $company->id : $company,
        );
    }
}
