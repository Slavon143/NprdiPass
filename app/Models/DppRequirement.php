<?php

namespace App\Models;

use App\Enums\DppRequirementLevel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $key
 * @property string|null $description
 * @property DppRequirementLevel $level
 * @property string|null $section
 * @property string|null $field
 * @property string|null $source
 * @property string|null $source_reference
 * @property string|null $source_version
 * @property CarbonInterface|null $effective_from
 * @property CarbonInterface|null $effective_to
 * @property string|null $applies_because
 * @property string|null $rule_code
 * @property string|null $rule_class
 * @property array<string, mixed>|null $evaluation_config
 * @property array<string, mixed>|null $action_target
 */
class DppRequirement extends Model
{
    protected $fillable = [
        'dpp_profile_id', 'key', 'title', 'description', 'level', 'section',
        'field', 'source', 'source_reference', 'applies_because', 'rule_code',
        'rule_class', 'evaluation_config', 'action_target', 'sort_order',
        'source_version', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'level' => DppRequirementLevel::class,
            'evaluation_config' => 'array',
            'action_target' => 'array',
            'sort_order' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DppProfile::class, 'dpp_profile_id');
    }
}
