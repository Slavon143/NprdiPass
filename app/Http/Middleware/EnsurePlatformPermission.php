<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformPermission
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->status === UserStatus::Active && $user->can($permission), 403);

        return $next($request);
    }
}
