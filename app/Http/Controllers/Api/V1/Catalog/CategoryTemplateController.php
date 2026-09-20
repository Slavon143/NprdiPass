<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Taxonomy\TaxonomyGovernanceAction;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\CategoryTemplateResource;
use App\Models\Catalog\CategoryTemplate;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryTemplateController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function index(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $category,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $category = $this->resolveCategory($company, $category);
        $this->authorizeCategoryView($category);
        $templates = CategoryTemplate::query()
            ->forCompany($company)
            ->where('category_id', $category->getKey())
            ->with(['category:id,uuid', 'attributes.definition', 'dppDefaults'])
            ->orderByDesc('version')
            ->get();

        return $response->success(CategoryTemplateResource::collection($templates)->resolve($request));
    }

    public function store(
        Request $request,
        TokenCurrentCompany $currentCompany,
        TaxonomyGovernanceAction $action,
        ApiResponse $response,
        string $category,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $category = $this->resolveCategory($company, $category);
        $this->authorizeCategoryUpdate($category);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'readiness_profile' => ['nullable', 'string', 'max:100'],
            'readiness_profile_version' => ['nullable', 'integer', 'min:1'],
        ]);
        $template = $action->createTemplate($this->actor($request), $company, $category, $validated)
            ->load(['category:id,uuid', 'attributes.definition', 'dppDefaults']);

        return $response->created((new CategoryTemplateResource($template))->resolve($request));
    }

    public function show(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $category,
        string $template,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $category = $this->resolveCategory($company, $category);
        $this->authorizeCategoryView($category);
        $template = $this->resolveCategoryTemplate($company, $category, $template)
            ->load(['category:id,uuid', 'attributes.definition', 'dppDefaults']);

        return $response->success((new CategoryTemplateResource($template))->resolve($request));
    }

    public function update(
        Request $request,
        TokenCurrentCompany $currentCompany,
        TaxonomyGovernanceAction $action,
        ApiResponse $response,
        string $category,
        string $template,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $category = $this->resolveCategory($company, $category);
        $this->authorizeCategoryUpdate($category);
        $template = $this->resolveCategoryTemplate($company, $category, $template);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'attributes' => ['present', 'array', 'max:200'],
            'attributes.*.attribute_uuid' => ['required', 'uuid'],
            'attributes.*.requirement' => ['required', 'in:required,recommended'],
            'attributes.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'dpp_defaults' => ['present', 'array'],
            'dpp_defaults.*' => ['array'],
        ]);
        $template = $action->configureTemplate(
            $this->actor($request),
            $company,
            $category,
            $template,
            $validated['name'],
            array_values($validated['attributes']),
            $validated['dpp_defaults'],
        )->load('category:id,uuid');

        return $response->success((new CategoryTemplateResource($template))->resolve($request));
    }

    public function activate(
        Request $request,
        TokenCurrentCompany $currentCompany,
        TaxonomyGovernanceAction $action,
        ApiResponse $response,
        string $category,
        string $template,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $category = $this->resolveCategory($company, $category);
        $this->authorizeCategoryUpdate($category);
        $template = $this->resolveCategoryTemplate($company, $category, $template);
        $template = $action->activateTemplate($this->actor($request), $company, $category, $template)
            ->load('category:id,uuid');

        return $response->success((new CategoryTemplateResource($template))->resolve($request));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
