<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'organization_number',
        'country_code',
        'billing_email',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'settings' => 'array',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(CompanyMembership::class)
            ->withPivot(['id', 'role', 'is_owner', 'joined_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }
}
