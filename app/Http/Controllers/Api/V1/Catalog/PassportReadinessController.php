<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Passports\RecordPassportValidationRun;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Passports\PassportReadinessResource;
use App\Models\User;
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
        RecordPassportValidationRun $recordValidationRun,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $context = $builder->build($company, $resolvedProduct);
        $result = $evaluator->evaluate($context);
        $actor = $request->user();

        if ($context->passport !== null && $context->currentDraft !== null) {
            $recordValidationRun->handle(
                $company,
                $context->passport,
                $context->currentDraft,
                $result,
                $actor instanceof User ? $actor : null,
            );
        }

        return $response->success(new PassportReadinessResource($result));
    }
}
