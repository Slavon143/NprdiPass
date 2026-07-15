<?php

namespace App\Http\Api;

use App\Audit\AuditContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiResponse
{
    public function __construct(
        private readonly Request $request,
    ) {}

    /** @param array<string, mixed> $meta */
    public function success(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return $this->secure(response()->json([
            'data' => $data,
            'meta' => $this->meta($meta),
            'error' => null,
        ], $status));
    }

    /** @param array<string, mixed> $meta */
    public function created(mixed $data, array $meta = []): JsonResponse
    {
        return $this->success($data, $meta, 201);
    }

    public function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $meta
     */
    public function error(
        string $code,
        string $message,
        int $status,
        array $details = [],
        array $meta = [],
    ): JsonResponse {
        return $this->secure(response()->json([
            'data' => null,
            'meta' => $this->meta($meta),
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $status));
    }

    /** @param array<string, mixed> $errors */
    public function validationError(array $errors): JsonResponse
    {
        return $this->error(
            'validation_error',
            'The given data was invalid.',
            422,
            $errors,
        );
    }

    /**
     * @param  array<int, mixed>  $data
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     */
    public function paginated(array $data, LengthAwarePaginator $paginator): JsonResponse
    {
        $links = [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        return $this->success($data, [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'links' => $links,
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function meta(array $meta): array
    {
        $requestId = $this->request->attributes->get(AuditContext::REQUEST_ID_ATTRIBUTE);

        if (is_string($requestId) && $requestId !== '') {
            $meta['request_id'] = $requestId;
        }

        return $meta;
    }

    private function secure(JsonResponse $response): JsonResponse
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store');

        $requestId = $this->request->attributes->get(AuditContext::REQUEST_ID_ATTRIBUTE);

        if (is_string($requestId) && $requestId !== '') {
            $response->headers->set('X-Request-ID', $requestId);
        }

        return $response;
    }
}
