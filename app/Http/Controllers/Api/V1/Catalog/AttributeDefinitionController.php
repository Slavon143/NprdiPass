<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Attributes\ArchiveAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\CreateAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\RestoreAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\UpdateAttributeDefinitionAction;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\AttributeDefinitionResource;
use App\Models\Catalog\AttributeDefinition;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttributeDefinitionController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function index(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $this->authorizeAttributeViewAny($company);

        $perPage = min((int) ($request->input('per_page', 50)), 100);
        $paginator = AttributeDefinition::query()
            ->forCompany($company)
            ->withCount(['options', 'productValues', 'variantValues'])
            ->ordered()
            ->paginate($perPage);

        return $response->paginated(
            AttributeDefinitionResource::collection($paginator)->resolve($request),
            $paginator,
        );
    }

    public function store(
        Request $request,
        TokenCurrentCompany $currentCompany,
        CreateAttributeDefinitionAction $action,
        ApiResponse $response,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $this->authorizeAttributeManage($company);

        $request->merge([
            'required' => $request->boolean('required'),
            'filterable' => $request->boolean('filterable'),
            'searchable' => $request->boolean('searchable'),
            'sort_order' => $request->input('sort_order', 0),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'string', 'in:text,integer,decimal,boolean,date,select,multiselect'],
            'scope' => ['required', 'string', 'in:product,variant,both'],
            'unit' => ['nullable', 'string', 'max:50'],
            'required' => ['required', 'boolean'],
            'filterable' => ['required', 'boolean'],
            'searchable' => ['required', 'boolean'],
            'validation_rules' => ['nullable', 'array'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $definition = $action->execute($this->actor($request), $company, $validated);

        return $response->created(
            (new AttributeDefinitionResource($definition))->resolve($request),
        );
    }

    public function show(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $attribute,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $definition = $this->resolveAttributeDefinition($company, $attribute);
        $this->authorizeAttributeView($definition);

        $definition->load([
            'options' => fn ($query) => $query->ordered(),
            'createdBy',
            'updatedBy',
        ])->loadCount(['productValues', 'variantValues']);

        return $response->success(
            (new AttributeDefinitionResource($definition))->resolve($request),
        );
    }

    public function update(
        Request $request,
        TokenCurrentCompany $currentCompany,
        UpdateAttributeDefinitionAction $action,
        ApiResponse $response,
        string $attribute,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $definition = $this->resolveAttributeDefinition($company, $attribute);
        $this->authorizeAttributeUpdate($definition);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'unit' => ['nullable', 'string', 'max:50'],
            'required' => ['nullable', 'boolean'],
            'filterable' => ['nullable', 'boolean'],
            'searchable' => ['nullable', 'boolean'],
            'validation_rules' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $action->execute($this->actor($request), $company, $definition, $validated);
        $definition->refresh()->load(['options' => fn ($query) => $query->ordered()]);

        return $response->success(
            (new AttributeDefinitionResource($definition))->resolve($request),
        );
    }

    public function archive(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ArchiveAttributeDefinitionAction $action,
        ApiResponse $response,
        string $attribute,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $definition = $this->resolveAttributeDefinition($company, $attribute);
        $this->authorizeAttributeArchive($definition);

        $action->execute($this->actor($request), $company, $definition);
        $definition->refresh()->load(['options' => fn ($query) => $query->ordered()]);

        return $response->success(
            (new AttributeDefinitionResource($definition))->resolve($request),
        );
    }

    public function restore(
        Request $request,
        TokenCurrentCompany $currentCompany,
        RestoreAttributeDefinitionAction $action,
        ApiResponse $response,
        string $attribute,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $definition = $this->resolveAttributeDefinition($company, $attribute);
        $this->authorizeAttributeRestore($definition);

        $action->execute($this->actor($request), $company, $definition);
        $definition->refresh()->load(['options' => fn ($query) => $query->ordered()]);

        return $response->success(
            (new AttributeDefinitionResource($definition))->resolve($request),
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
