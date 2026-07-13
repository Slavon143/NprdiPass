<?php

namespace App\Http\Middleware;

use App\Domain\Api\Exceptions\ApiTokenInvalid;
use App\Models\Company;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveApiCompany
{
    public function __construct(
        private readonly TokenCurrentCompany $currentCompany,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken) {
            throw new ApiTokenInvalid;
        }

        $company = Company::query()->find($token->getAttribute('company_id'));

        if (! $company instanceof Company) {
            throw new ApiTokenInvalid;
        }

        $this->currentCompany->set($company);
        $request->attributes->set('apiCurrentCompany', $company);

        try {
            return $next($request);
        } finally {
            $this->currentCompany->clear();
        }
    }
}
