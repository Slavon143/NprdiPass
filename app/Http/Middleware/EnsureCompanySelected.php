<?php

namespace App\Http\Middleware;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanySelected
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('currentCompany') instanceof Company) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $this->unauthenticated($request);
        }

        $companies = $user->companies()
            ->select(['companies.id', 'companies.status'])
            ->get();

        $hasSuspendedCompany = $companies->contains(
            fn (Company $company): bool => $company->status === CompanyStatus::Suspended,
        );

        if ($request->expectsJson()) {
            if ($companies->isNotEmpty() && $hasSuspendedCompany && $companies->every(
                fn (Company $company): bool => $company->status !== CompanyStatus::Active,
            )) {
                return response()->json([
                    'message' => 'Current company is suspended.',
                ], 423);
            }

            return response()->json([
                'message' => 'Current company is not selected.',
            ], 409);
        }

        if ($companies->isEmpty()) {
            return redirect()->route('companies.none');
        }

        $hasActiveCompany = $companies->contains(
            fn (Company $company): bool => $company->status === CompanyStatus::Active,
        );

        if (! $hasActiveCompany && $hasSuspendedCompany) {
            return redirect()->route('company.suspended');
        }

        if (! $hasActiveCompany) {
            return redirect()->route('companies.none');
        }

        return redirect()->route('companies.select');
    }

    private function unauthenticated(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('login');
    }
}
