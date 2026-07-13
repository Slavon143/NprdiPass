<?php

namespace App\Http\Middleware;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiCompanyMembership
{
    public function __construct(
        private readonly TokenCurrentCompany $currentCompany,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        $membership = CompanyMembership::query()
            ->where('company_id', $this->currentCompany->require()->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if (! $membership instanceof CompanyMembership) {
            throw new AuthorizationException;
        }

        $request->attributes->set('apiCurrentMembership', $membership);

        return $next($request);
    }
}
