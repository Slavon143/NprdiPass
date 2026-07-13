<?php

namespace App\Http\Middleware;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->attributes->get('currentCompany');

        if (! $company instanceof Company) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Current company is not selected.'], 409)
                : redirect()->route('companies.select');
        }

        return match ($company->status) {
            CompanyStatus::Active => $next($request),
            CompanyStatus::Suspended => $request->expectsJson()
                ? response()->json(['message' => 'Current company is suspended.'], 423)
                : redirect()->route('company.suspended'),
            CompanyStatus::Archived => $request->expectsJson()
                ? response()->json(['message' => 'Current company is archived.'], 403)
                : abort(403),
        };
    }
}
