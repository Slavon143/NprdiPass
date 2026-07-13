<?php

use App\Http\Middleware\EnsureCompanyIsActive;
use App\Http\Middleware\EnsureCompanySelected;
use App\Http\Middleware\EnsureRequestId;
use App\Http\Middleware\EnsureUserBelongsToCurrentCompany;
use App\Http\Middleware\InvitationSecurityHeaders;
use App\Http\Middleware\ResolveCurrentCompany;
use App\Tenancy\Exceptions\CurrentCompanyNotSet;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(EnsureRequestId::class);

        $middleware->alias([
            'company.resolve' => ResolveCurrentCompany::class,
            'company.selected' => EnsureCompanySelected::class,
            'company.member' => EnsureUserBelongsToCurrentCompany::class,
            'company.active' => EnsureCompanyIsActive::class,
            'invitation.secure' => InvitationSecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (CurrentCompanyNotSet $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 409);
            }

            return redirect()->route($request->user() === null ? 'login' : 'companies.select');
        });
    })->create();
