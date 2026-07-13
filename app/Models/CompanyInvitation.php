<?php

namespace App\Models;

use App\Enums\CompanyRole;
use App\Models\Concerns\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\CompanyInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CompanyRole $role
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $cancelled_at
 */
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
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
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

    public function isCancelled(): bool
    {
        return $this->getAttribute('cancelled_at') !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isCancelled() && ! $this->isExpired();
    }
}
