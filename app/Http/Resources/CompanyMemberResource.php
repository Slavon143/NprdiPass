<?php

namespace App\Http\Resources;

use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompanyMembership */
class CompanyMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->user;
        abort_unless($user instanceof User, 500);

        return [
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'role' => $this->role->value,
            'is_owner' => $this->is_owner,
            'status' => $user->status->value,
            'joined_at' => $this->joined_at?->toISOString(),
        ];
    }
}
