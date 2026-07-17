<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Passports\PassportReadinessResource;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PassportReadinessController extends Controller
{
    use ResolvesCatalogApiResources;

    public function show(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ReadinessContextBuilder $builder,
        PassportReadinessEvaluator $evaluator,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $context = $builder->build($company, $resolvedProduct);
        $result = $evaluator->evaluate($context);

        return $response->success(new PassportReadinessResource($result));
    }
}
