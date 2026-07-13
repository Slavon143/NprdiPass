<?php

namespace App\Http\Middleware;

use App\Domain\Api\Exceptions\ApiCompanyInactive;
use App\Enums\CompanyStatus;
use App\Tenancy\TokenCurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiCompanyIsActive
{
    public function __construct(
        private readonly TokenCurrentCompany $currentCompany,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->currentCompany->require()->status;

        if ($status === CompanyStatus::Suspended) {
            throw new ApiCompanyInactive(423);
        }

        if ($status !== CompanyStatus::Active) {
            throw new ApiCompanyInactive(403);
        }

        return $next($request);
    }
}
