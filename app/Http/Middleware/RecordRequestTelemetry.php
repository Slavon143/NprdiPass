<?php

namespace App\Http\Middleware;

use App\Services\Observability\PublicResolverMetrics;
use App\Services\Observability\RequestTelemetry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RecordRequestTelemetry
{
    public function __construct(private readonly RequestTelemetry $telemetry, private readonly PublicResolverMetrics $resolverMetrics) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $logging = (bool) config('observability.request_logging_enabled', true);
        if (! $logging && ! (bool) config('observability.resolver_metrics.enabled', false)) {
            return $next($request);
        }

        $started = hrtime(true);
        $this->telemetry->start();

        try {
            $response = $next($request);
        } finally {
            $this->telemetry->stop();
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $route = $request->route();
        $query = $this->telemetry->summary();
        if ($query['public_resolver_ms'] !== null) {
            $this->resolverMetrics->record($query['public_resolver_ms']);
        }

        if ($logging) {
            Log::info('observability.request', [
                'request_id' => $request->attributes->get('nordipass_request_id'),
                'method' => $request->method(),
                'route' => $route?->getName() ?? 'unmatched',
                'path_template' => $route?->uri() ?? '/',
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'slow_request' => $durationMs >= (int) config('observability.slow_request_ms', 1000),
                'query_count' => $query['query_count'],
                'query_ms' => $query['query_ms'],
                'slow_queries' => $query['slow_queries'],
                'public_resolver_ms' => $query['public_resolver_ms'],
            ]);
        }

        return $response;
    }
}
