<?php

namespace App\Http\Middleware;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\CurrentMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserBelongsToCurrentCompany
{
    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly CurrentMembership $currentMembership,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $company = $request->attributes->get('currentCompany');

        if (! $user instanceof User || ! $company instanceof Company) {
            $this->currentCompany->clear();

            return $request->expectsJson()
                ? response()->json(['message' => 'Current company is not selected.'], 409)
                : redirect()->route('companies.select');
        }

        $membership = $this->currentMembership->get($user, $company);

        if ($membership === null) {
            $this->currentCompany->clear();
            $request->attributes->remove('currentCompany');

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'You do not have access to the current company.',
                ], 403);
            }

            $hasActiveCompany = $user->companies()
                ->where('companies.status', CompanyStatus::Active->value)
                ->exists();

            return redirect()->route($hasActiveCompany ? 'companies.select' : 'companies.none');
        }

        $request->attributes->set('currentMembership', $membership);

        return $next($request);
    }
}
