<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Database\Factories\CompanyMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CompanyMembership extends Pivot
{
    /** @use HasFactory<CompanyMembershipFactory> */
    use HasFactory;

    protected $table = 'company_user';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'company_id',
        'user_id',
        'role',
        'is_owner',
        'joined_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (CompanyMembership $membership): void {
            $role = $membership->getAttribute('role');
            $isOwner = $role instanceof CompanyRole
                ? $role === CompanyRole::Owner
                : $role === CompanyRole::Owner->value;

            $membership->setAttribute('is_owner', $isOwner);
        });
    }

    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'is_owner' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
