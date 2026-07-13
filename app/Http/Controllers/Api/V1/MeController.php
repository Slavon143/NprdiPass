<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\UserResource;
use App\Models\CompanyMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
    ): JsonResponse {
        $user = $request->user();
        $membership = $request->attributes->get('apiCurrentMembership');
        abort_unless($user instanceof User && $membership instanceof CompanyMembership, 403);
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 401);

        return $response->success([
            'user' => (new UserResource($user))->resolve($request),
            'company' => (new CompanyResource($currentCompany->require()))->resolve($request),
            'role' => $membership->role->value,
            'abilities' => array_values($token->abilities),
        ]);
    }
}
