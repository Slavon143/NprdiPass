<?php

namespace App\Http\Controllers;

use App\Actions\Api\CreateCompanyApiToken;
use App\Actions\Api\RevokeCompanyApiToken;
use App\Enums\ApiTokenAbility;
use App\Enums\CompanyPermission;
use App\Http\Requests\StoreApiTokenRequest;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ApiTokenController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::ApiTokensView->value, $company);

        return view()->make('settings.api-tokens.index', [
            'company' => $company,
            'tokens' => $company->apiTokens()
                ->with('tokenable')
                ->latest()
                ->get(),
            'abilities' => ApiTokenAbility::cases(),
            'allowNonExpiringTokens' => (bool) config('api.allow_non_expiring_tokens', false),
            'defaultExpirationDays' => (int) config('api.default_token_expiration_days', 90),
        ]);
    }

    public function store(
        StoreApiTokenRequest $request,
        CurrentCompany $currentCompany,
        CreateCompanyApiToken $action,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $expiration = (string) $request->validated('expiration');
        $expiresAt = match ($expiration) {
            '30_days' => now()->addDays(30),
            '90_days' => now()->addDays(90),
            '1_year' => now()->addDays(365),
            'never' => null,
            default => abort(422),
        };
        $newToken = $action->execute(
            $user,
            $currentCompany->require(),
            (string) $request->validated('name'),
            (array) $request->validated('abilities'),
            $expiresAt,
        );

        return response()->view('settings.api-tokens.created', [
            'tokenName' => $newToken->accessToken->name,
            'plainTextToken' => $newToken->plainTextToken,
            'expiresAt' => $newToken->accessToken->expires_at,
        ]);
    }

    public function destroy(
        Request $request,
        CurrentCompany $currentCompany,
        RevokeCompanyApiToken $action,
        string $token,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(ctype_digit($token), 404);
        $company = $currentCompany->require();
        $accessToken = $company->apiTokens()->whereKey((int) $token)->firstOrFail();

        $action->execute($user, $company, $accessToken);

        return redirect()
            ->route('settings.api-tokens.index')
            ->with('success', 'API token revoked.');
    }
}
