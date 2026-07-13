<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(ApiResponse $response): JsonResponse
    {
        return $response->success([
            'status' => 'ok',
            'service' => 'NordiPass API',
            'version' => 'v1',
        ]);
    }
}
