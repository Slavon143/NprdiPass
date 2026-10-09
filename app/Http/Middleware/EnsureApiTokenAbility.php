<?php

namespace App\Http\Middleware;

use App\Authorization\ApiTokenMutationAuthorizer;
use App\Domain\Api\Exceptions\ApiTokenAbilityMissing;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Billing\CommercialUsageAdmission;
use App\Tenancy\TokenCurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenAbility
{
    public function __construct(private readonly ApiTokenMutationAuthorizer $authorizer) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $user instanceof User || ! $token instanceof PersonalAccessToken || ! $token->can($ability)) {
            throw new ApiTokenAbilityMissing;
        }

        return $this->authorizer->withToken($token, $ability, function () use ($user, $ability, $request, $next): Response {
            if ($request->attributes->get('nordipass.api_usage_admitted') !== true) {
                $company = app(TokenCurrentCompany::class)->require();
                // A client-controlled X-Request-ID is never a quota idempotency key.
                $operationId = (string) Str::uuid();
                DB::transaction(function () use ($user, $company, $ability, $operationId): void {
                    $this->authorizer->authorize($user, $company, $ability, sharedCompany: true);
                    app(CommercialUsageAdmission::class)->consume($company, 'api', $operationId);
                }, 5);
                $request->attributes->set('nordipass.api_usage_admitted', true);
            }

            return $next($request);
        });
    }
}
