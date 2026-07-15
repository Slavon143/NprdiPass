<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Attributes\ArchiveAttributeOptionAction;
use App\Actions\Catalog\Attributes\CreateAttributeOptionAction;
use App\Actions\Catalog\Attributes\ReorderAttributeOptionsAction;
use App\Actions\Catalog\Attributes\RestoreAttributeOptionAction;
use App\Actions\Catalog\Attributes\UpdateAttributeOptionAction;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\AttributeOptionResource;
use App\Models\Catalog\AttributeOption;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttributeOptionController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function index(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $attribute,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $definition = $this->resolveAttributeDefinition($company, $attribute);
        $this->authorizeAttributeView($definition);

        $options = AttributeOption::query()
            ->where('attribute_definition_id', $definition->getKey())
            ->ordered()
            ->get();

        return $response->success(
            AttributeOptionResource::collection($options)->resolve($request),
        );
    }

    public function store(
        Request $request,
        TokenCurrentCompany $currentCompany,
        CreateAttributeOptionAction $action,
        ApiResponse $response,
        string $attribute,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $definition = $this->resolveAttributeDefinition($company, $attribute);
        $this->authorizeOptionManage($definition);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $option = $action->execute($this->actor($request), $company, $definition, $validated);

        return $response->created(
            (new AttributeOptionResource($option))->resolve($request),
        );
    }

    public function update(
        Request $request,
        TokenCurrentCompany $currentCompany,
        UpdateAttributeOptionAction $action,
        ApiResponse $response,
        string $attribute,
        int $option,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $definition = $this->resolveAttributeDefinition($company, $attribute);
        $option = $this->resolveAttributeOption($definition, $option);
        $this->authorizeOptionUpdate($option);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $action->execute($this->actor($request), $company, $definition, $option, $validated);
        $option->refresh();

        return $response->success(
            (new AttributeOptionResource($option))->resolve($request),
        );
    }

    public function archive(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ArchiveAttributeOptionAction $action,
        ApiResponse $response,
        string $attribute,
        int $option,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $definition = $this->resolveAttributeDefinition($company, $attribute);
        $option = $this->resolveAttributeOption($definition, $option);
        $this->authorizeOptionArchive($option);

        $action->execute($this->actor($request), $company, $definition, $option);
        $option->refresh();

        return $response->success(
            (new AttributeOptionResource($option))->resolve($request),
        );
    }

    public function restore(
        Request $request,
        TokenCurrentCompany $currentCompany,
        RestoreAttributeOptionAction $action,
        ApiResponse $response,
        string $attribute,
        int $option,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $definition = $this->resolveAttributeDefinition($company, $attribute);
        $option = $this->resolveAttributeOption($definition, $option);
        $this->authorizeOptionRestore($option);

        $action->execute($this->actor($request), $company, $definition, $option);
        $option->refresh();

        return $response->success(
            (new AttributeOptionResource($option))->resolve($request),
        );
    }

    public function reorder(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ReorderAttributeOptionsAction $action,
        ApiResponse $response,
        string $attribute,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $definition = $this->resolveAttributeDefinition($company, $attribute);
        $this->authorizeOptionManage($definition);

        $validated = $request->validate([
            'ordered_uuids' => ['required', 'array', 'min:1', 'max:200'],
        ]);

        /** @var list<int> $orderedIds */
        $orderedIds = array_map('intval', $validated['ordered_uuids']);
        $action->execute($this->actor($request), $company, $definition, $orderedIds);

        $options = AttributeOption::query()
            ->where('attribute_definition_id', $definition->getKey())
            ->ordered()
            ->get();

        return $response->success(
            AttributeOptionResource::collection($options)->resolve($request),
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
