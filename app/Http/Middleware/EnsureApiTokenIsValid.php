<?php

namespace App\Http\Middleware;

use App\Domain\Api\Exceptions\ApiTokenExpired;
use App\Domain\Api\Exceptions\ApiTokenInvalid;
use App\Enums\UserStatus;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenIsValid
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if ($request->bearerToken() === null || ! $token instanceof PersonalAccessToken) {
            throw new ApiTokenInvalid;
        }

        if ($token->isExpired()) {
            throw new ApiTokenExpired;
        }

        if (! is_int($token->getAttribute('company_id')) || $token->getAttribute('company_id') < 1) {
            throw new ApiTokenInvalid;
        }

        if ($user->trashed() || $user->status !== UserStatus::Active) {
            throw new ApiTokenInvalid;
        }

        return $next($request);
    }
}
