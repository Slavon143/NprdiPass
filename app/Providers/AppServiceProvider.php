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
use Illuminate\Support\Str;
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

            return Limit::perMinute(config('rate_limits.invitations.manage_per_minute', 10))
                ->by("{$userKey}|{$companyKey}");
        });

        RateLimiter::for(
            'invitations.verify',
            fn (Request $request): Limit => Limit::perMinute(
                config('rate_limits.invitations.verify_per_minute', 20),
            )->by($request->ip()),
        );

        RateLimiter::for(
            'invitations.accept',
            fn (Request $request): Limit => Limit::perMinute(
                config('rate_limits.invitations.accept_per_minute', 10),
            )->by($request->ip()),
        );

        RateLimiter::for(
            'api-public',
            fn (Request $request): Limit => Limit::perMinute(
                config('rate_limits.api_public.per_minute', 60),
            )->by($request->ip()),
        );

        RateLimiter::for('api-authenticated', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenKey = $token instanceof PersonalAccessToken
                ? 'token:'.$token->getKey()
                : 'unauthenticated:'.$request->ip();

            return Limit::perMinute(config('rate_limits.api.per_minute', 120))->by($tokenKey);
        });

        RateLimiter::for('api-token-management', function (Request $request): Limit {
            $company = $request->attributes->get('currentCompany');
            $companyKey = $company instanceof Company ? $company->getKey() : 'none';
            $userKey = $request->user()?->getAuthIdentifier() ?? $request->ip();
            $operation = $request->isMethod('DELETE') ? 'revoke' : 'create';
            $limit = $operation === 'revoke'
                ? (int) config('rate_limits.token_management.revoke_per_minute', 30)
                : (int) config('rate_limits.token_management.create_per_minute', 10);

            return Limit::perMinute($limit)->by("{$operation}|{$userKey}|{$companyKey}");
        });

        RateLimiter::for('auth', function (Request $request): Limit {
            $email = (string) $request->input('email', '');
            $ip = (string) $request->ip();
            $key = hash('sha256', Str::lower(trim($email)).'|'.$ip);

            return Limit::perMinute(config('rate_limits.auth.per_minute', 5))->by($key);
        });

        RateLimiter::for('catalog-api-read', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenKey = $token instanceof PersonalAccessToken
                ? 'catalog-read:token:'.$token->getKey()
                : 'catalog-read:'.$request->ip();

            return Limit::perMinute(config('rate_limits.catalog_api.read_per_minute', 120))->by($tokenKey);
        });

        RateLimiter::for('catalog-api-write', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenKey = $token instanceof PersonalAccessToken
                ? 'catalog-write:token:'.$token->getKey()
                : 'catalog-write:'.$request->ip();

            return Limit::perMinute(config('rate_limits.catalog_api.write_per_minute', 60))->by($tokenKey);
        });

        RateLimiter::for('catalog-api-media', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenKey = $token instanceof PersonalAccessToken
                ? 'catalog-media:token:'.$token->getKey()
                : 'catalog-media:'.$request->ip();

            return Limit::perMinute(config('rate_limits.catalog_api.media_per_minute', 20))->by($tokenKey);
        });

        RateLimiter::for('catalog-api-lifecycle', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenKey = $token instanceof PersonalAccessToken
                ? 'catalog-lifecycle:token:'.$token->getKey()
                : 'catalog-lifecycle:'.$request->ip();

            return Limit::perMinute(config('rate_limits.catalog_api.lifecycle_per_minute', 30))->by($tokenKey);
        });

        RateLimiter::for('documents-api-read', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenKey = $token instanceof PersonalAccessToken
                ? 'documents-read:token:'.$token->getKey()
                : 'documents-read:'.$request->ip();

            return Limit::perMinute(config('rate_limits.documents_api.read_per_minute', 120))->by($tokenKey);
        });

        RateLimiter::for('documents-api-write', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenKey = $token instanceof PersonalAccessToken
                ? 'documents-write:token:'.$token->getKey()
                : 'documents-write:'.$request->ip();

            return Limit::perMinute(config('rate_limits.documents_api.write_per_minute', 60))->by($tokenKey);
        });

        RateLimiter::for('documents-api-media', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenKey = $token instanceof PersonalAccessToken
                ? 'documents-media:token:'.$token->getKey()
                : 'documents-media:'.$request->ip();

            return Limit::perMinute(config('rate_limits.documents_api.media_per_minute', 20))->by($tokenKey);
        });

        RateLimiter::for('passports-api-read', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenKey = $token instanceof PersonalAccessToken
                ? 'passports-read:token:'.$token->getKey()
                : 'passports-read:'.$request->ip();

            return Limit::perMinute(config('rate_limits.passports_api.read_per_minute', 120))->by($tokenKey);
        });

        RateLimiter::for('passports-api-write', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $tokenKey = $token instanceof PersonalAccessToken
                ? 'passports-write:token:'.$token->getKey()
                : 'passports-write:'.$request->ip();

            return Limit::perMinute(config('rate_limits.passports_api.write_per_minute', 60))->by($tokenKey);
        });

        RateLimiter::for('public-passport', function (Request $request): Limit {
            return Limit::perMinute(config('rate_limits.public_passport.per_minute', 60))
                ->by($request->ip());
        });

        RateLimiter::for('public-passport-assets', function (Request $request): Limit {
            return Limit::perMinute(config('rate_limits.public_passport.assets_per_minute', 120))
                ->by($request->ip());
        });
    }
}
