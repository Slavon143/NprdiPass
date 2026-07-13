<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListCompanyMembersRequest;
use App\Http\Resources\CompanyMemberResource;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;

class CompanyMembersController extends Controller
{
    public function __invoke(
        ListCompanyMembersRequest $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
    ): JsonResponse {
        $perPage = (int) $request->validated('per_page', 25);
        $memberships = $currentCompany->require()
            ->memberships()
            ->with('user')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        $data = CompanyMemberResource::collection($memberships->getCollection())->resolve($request);

        return $response->paginated($data, $memberships);
    }
}
