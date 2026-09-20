<?php

namespace App\Models\Fortnox;

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
 * @property int $mapping_id
 * @property FortnoxField $field
 * @property string $source
 * @property array<string, mixed>|null $manual_value
 * @property int|null $overridden_by
 * @property CarbonImmutable|null $overridden_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read FortnoxArticleMapping $mapping
 * @property-read User|null $overriddenBy
 */
class FortnoxFieldOverride extends Model
{
    use HasCompanyScope, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'field' => FortnoxField::class,
            'manual_value' => 'array',
            'overridden_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(FortnoxArticleMapping::class, 'mapping_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }
}
