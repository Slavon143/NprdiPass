<?php

namespace App\Providers;

use App\Models\Company;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\SessionCurrentCompany;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CurrentCompany::class, SessionCurrentCompany::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}
