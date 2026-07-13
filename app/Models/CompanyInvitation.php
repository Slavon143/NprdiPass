<?php

namespace App\Models;

use App\Enums\CompanyRole;
use App\Models\Concerns\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\CompanyInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyInvitation extends Model
{
    /** @use HasFactory<CompanyInvitationFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'email',
        'role',
        'expires_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->getAttribute('expires_at');

        return $expiresAt instanceof CarbonInterface && $expiresAt->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->getAttribute('accepted_at') !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }
}
