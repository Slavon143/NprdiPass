<?php

use App\Http\Api\ApiExceptionRenderer;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\ApiTokenSecretHeaders;
use App\Http\Middleware\EnsureApiCompanyIsActive;
use App\Http\Middleware\EnsureApiCompanyMembership;
use App\Http\Middleware\EnsureApiTokenAbility;
use App\Http\Middleware\EnsureApiTokenIsValid;
use App\Http\Middleware\EnsureCompanyIsActive;
use App\Http\Middleware\EnsureCompanySelected;
use App\Http\Middleware\EnsureRequestId;
use App\Http\Middleware\EnsureUserBelongsToCurrentCompany;
use App\Http\Middleware\InvitationSecurityHeaders;
use App\Http\Middleware\ResolveApiCompany;
use App\Http\Middleware\ResolveCurrentCompany;
use App\Tenancy\Exceptions\CurrentCompanyNotSet;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(EnsureRequestId::class);
        $middleware->prepend(AddSecurityHeaders::class);

        $middleware->api(append: [ApiSecurityHeaders::class]);

        $middleware->prependToPriorityList(ThrottleRequests::class, EnsureApiTokenIsValid::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, ResolveApiCompany::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, EnsureApiCompanyMembership::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, EnsureApiCompanyIsActive::class);

        $proxies = (string) env('TRUSTED_PROXIES', '');
        if ($proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? ['*'] : $proxies);
        }

        $hosts = (string) env('TRUSTED_HOSTS', 'localhost,127.0.0.1');
        if ($hosts !== '') {
            $middleware->trustHosts(at: array_map('trim', explode(',', $hosts)));
        }

        $middleware->alias([
            'company.resolve' => ResolveCurrentCompany::class,
            'company.selected' => EnsureCompanySelected::class,
            'company.member' => EnsureUserBelongsToCurrentCompany::class,
            'company.active' => EnsureCompanyIsActive::class,
            'invitation.secure' => InvitationSecurityHeaders::class,
            'api.token.valid' => EnsureApiTokenIsValid::class,
            'api.company.resolve' => ResolveApiCompany::class,
            'api.company.member' => EnsureApiCompanyMembership::class,
            'api.company.active' => EnsureApiCompanyIsActive::class,
            'api.ability' => EnsureApiTokenAbility::class,
            'api-token.secret' => ApiTokenSecretHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (CurrentCompanyNotSet $exception, Request $request) {
            if ($request->is('api/*')) {
                return app(ApiExceptionRenderer::class)->render($exception, $request);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 409);
            }

            return redirect()->route($request->user() === null ? 'login' : 'companies.select');
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ApiExceptionRenderer::class)->render($exception, $request);
        });

        $exceptions->context(function (Throwable $e, array $context): array {
            $request = request();

            return [
                'request_id' => $request?->attributes?->get('nordipass_request_id'),
            ];
        });
    })->create();
