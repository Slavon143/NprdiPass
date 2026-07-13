<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\CompanyResolver;
use App\Tenancy\Contracts\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentCompany
{
    public function __construct(
        private readonly CompanyResolver $resolver,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            $this->currentCompany->clear();

            return $next($request);
        }

        $company = $this->resolver->resolveFor($user);

        if ($company !== null) {
            $request->attributes->set('currentCompany', $company);
        } else {
            $request->attributes->remove('currentCompany');
        }

        return $next($request);
    }
}
