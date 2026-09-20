<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Taxonomy\TaxonomyGovernanceAction;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\User;
use App\Services\Catalog\Taxonomy\ProductReadinessProfileResolver;
use App\Services\Catalog\Taxonomy\ProductTaxonomyDiagnosticsService;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductTaxonomyController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function show(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ProductTaxonomyDiagnosticsService $diagnostics,
        ProductReadinessProfileResolver $profiles,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductView($product);

        return $response->success($this->payload($product, $diagnostics, $profiles));
    }

    public function acknowledge(
        Request $request,
        TokenCurrentCompany $currentCompany,
        TaxonomyGovernanceAction $action,
        ProductTaxonomyDiagnosticsService $diagnostics,
        ProductReadinessProfileResolver $profiles,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductUpdate($product);
        $product = $action->acknowledgeProduct($this->actor($request), $company, $product);

        return $response->success($this->payload($product, $diagnostics, $profiles));
    }

    public function updateProfileOverride(
        Request $request,
        TokenCurrentCompany $currentCompany,
        TaxonomyGovernanceAction $action,
        ProductTaxonomyDiagnosticsService $diagnostics,
        ProductReadinessProfileResolver $profiles,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $validated = $request->validate([
            'readiness_profile' => ['nullable', 'string', 'max:100'],
            'readiness_profile_version' => ['nullable', 'integer', 'min:1'],
        ]);
        $product = $action->setProductProfileOverride(
            $this->actor($request),
            $company,
            $product,
            $validated['readiness_profile'] ?? null,
            $validated['readiness_profile_version'] ?? null,
        );

        return $response->success($this->payload($product, $diagnostics, $profiles));
    }

    /** @return array<string, mixed> */
    private function payload(
        Product $product,
        ProductTaxonomyDiagnosticsService $diagnostics,
        ProductReadinessProfileResolver $profiles,
    ): array {
        $diagnosis = $diagnostics->diagnose($product);
        $profile = $profiles->resolve($product);
        $template = $product->primaryCategory?->activeTemplate;

        return [
            'product_uuid' => $product->uuid,
            'needs_review' => $diagnosis['needs_review'],
            'active_template' => $template === null ? null : [
                'uuid' => $template->uuid,
                'version' => $template->version,
                'name' => $template->name,
            ],
            'acknowledged_template_id' => $product->taxonomy_template_id,
            'reviewed_at' => $product->taxonomy_reviewed_at?->toISOString(),
            'missing_required' => array_map($this->attributePayload(...), $diagnosis['missing_required']),
            'missing_recommended' => array_map($this->attributePayload(...), $diagnosis['missing_recommended']),
            'effective_readiness_profile' => [
                'code' => $profile->code,
                'version' => $profile->version,
                'name' => $profile->name,
                'fingerprint' => $profile->fingerprint,
            ],
            'profile_override' => $product->readiness_profile_override === null ? null : [
                'code' => $product->readiness_profile_override,
                'version' => $product->readiness_profile_version_override,
            ],
        ];
    }

    /** @return array{uuid: string, code: string, name: string, scope: string} */
    private function attributePayload(AttributeDefinition $definition): array
    {
        return [
            'uuid' => $definition->uuid,
            'code' => $definition->code,
            'name' => $definition->name,
            'scope' => $definition->scope->value,
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
