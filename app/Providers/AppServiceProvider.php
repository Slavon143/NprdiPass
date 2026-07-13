<?php

namespace App\Providers;

use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\SessionCurrentCompany;
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
        //
    }
}
