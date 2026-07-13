<?php

namespace App\Http\Middleware;

use App\Audit\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureRequestId
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-ID');
        $requestId = is_string($incoming) && preg_match('/\A[A-Za-z0-9._-]{1,100}\z/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set(AuditContext::REQUEST_ID_ATTRIBUTE, $requestId);
        Log::withContext(['request_id' => $requestId]);

        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } finally {
            Log::withoutContext(['request_id']);
        }
    }
}
