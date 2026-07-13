<?php

namespace App\Http\Middleware;

use App\Domain\Api\Exceptions\ApiTokenAbilityMissing;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenAbility
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken || ! $token->can($ability)) {
            throw new ApiTokenAbilityMissing;
        }

        return $next($request);
    }
}
