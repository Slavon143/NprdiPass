<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __invoke(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
    ): JsonResponse {
        return $response->success(
            (new CompanyResource($currentCompany->require()))->resolve($request),
        );
    }
}
