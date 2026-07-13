<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\PersonalAccessToken;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\SessionCurrentCompany;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CurrentCompany::class, SessionCurrentCompany::class);
        $this->app->scoped(TokenCurrentCompany::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        RateLimiter::for('invitations.manage', function (Request $request): Limit {
            $company = $request->attributes->get('currentCompany');
            $companyKey = $company instanceof Company ? $company->getKey() : 'none';
            $userKey = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(10)->by("{$userKey}|{$companyKey}");
        });

        RateLimiter::for(
            'invitations.verify',
            fn (Request $request): Limit => Limit::perMinute(20)->by($request->ip()),
        );

        RateLimiter::for(
            'invitations.accept',
            fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()),
        );

        RateLimiter::for(
            'api-public',
            fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()),
        );

        RateLimiter::for('api-authenticated', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenKey = $token instanceof PersonalAccessToken
                ? 'token:'.$token->getKey()
                : 'unauthenticated:'.$request->ip();

            return Limit::perMinute(120)->by($tokenKey);
        });

        RateLimiter::for('api-token-management', function (Request $request): Limit {
            $company = $request->attributes->get('currentCompany');
            $companyKey = $company instanceof Company ? $company->getKey() : 'none';
            $userKey = $request->user()?->getAuthIdentifier() ?? $request->ip();
            $operation = $request->isMethod('DELETE') ? 'revoke' : 'create';
            $limit = $operation === 'revoke' ? 30 : 10;

            return Limit::perMinute($limit)->by("{$operation}|{$userKey}|{$companyKey}");
        });
    }
}
