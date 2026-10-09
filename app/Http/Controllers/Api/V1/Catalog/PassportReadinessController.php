<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Passports\RecordPassportValidationRun;
use App\Enums\Catalog\ProductStatus;
use App\Exceptions\Billing\CommercialReadOnlyException;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Passports\PassportReadinessResource;
use App\Models\User;
use App\Services\Billing\CommercialAccessPolicy;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Services\Passports\Readiness\StoredPassportReadiness;
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

        if (app(CommercialAccessPolicy::class)->isReadOnly($company)
            || ! $resolvedProduct->managed || $resolvedProduct->status === ProductStatus::Archived) {
            $stored = app(StoredPassportReadiness::class)->latest($company, $resolvedProduct);

            return $stored === null
                ? $response->error('resource_not_found', 'No stored readiness evaluation is available.', 404)
                : $response->success(new PassportReadinessResource($stored), ['readiness_source' => 'stored']);
        }

        $context = $builder->build($company, $resolvedProduct);
        $result = $evaluator->evaluate($context);
        $actor = $request->user();

        if ($context->passport !== null && $context->currentDraft !== null
            && ! app(CommercialAccessPolicy::class)->isReadOnly($company)) {
            try {
                $recordValidationRun->handle(
                    $company, $context->passport, $context->currentDraft, $result,
                    $actor instanceof User ? $actor : null,
                );
            } catch (CommercialReadOnlyException) {
                // A read-only response must not persist a new validation run.
            }
        }

        return $response->success(new PassportReadinessResource($result));
    }
}
