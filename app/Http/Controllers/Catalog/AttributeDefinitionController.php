<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Attributes\ArchiveAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\BulkDeleteAttributesAction;
use App\Actions\Catalog\Attributes\CreateAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\DeleteAttributeAction;
use App\Actions\Catalog\Attributes\RestoreAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\UpdateAttributeDefinitionAction;
use App\Actions\Catalog\Taxonomy\TaxonomyGovernanceAction;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Attributes\BulkDeleteAttributesRequest;
use App\Http\Requests\Catalog\Attributes\StoreAttributeDefinitionRequest;
use App\Http\Requests\Catalog\Attributes\UpdateAttributeDefinitionRequest;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AttributeDefinitionController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize('viewAny', [AttributeDefinition::class, $company]);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:product,variant,both,all'],
            'type' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'in:active,archived,all'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $scope = $filters['scope'] ?? 'all';
        $type = $filters['type'] ?? 'all';
        $status = $filters['status'] ?? 'all';

        $query = AttributeDefinition::query()->forCompany($company)
            ->withCount(['options', 'productValues', 'variantValues'])
            ->ordered();

        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('name', 'like', $needle)->orWhere('code', 'like', $needle);
            });
        }

        if ($scope !== 'all') {
            $query->where('scope', $scope);
        }

        if ($type !== 'all') {
            $query->where('type', $type);
        }

        if ($status === AttributeDefinitionStatus::Active->value) {
            $query->active();
        } elseif ($status === AttributeDefinitionStatus::Archived->value) {
            $query->archived();
        }

        $definitions = $query->paginate(50)->withQueryString();
        $allTypes = AttributeDataType::cases();

        return view('catalog.attributes.index', [
            'company' => $company,
            'definitions' => $definitions,
            'canManage' => $request->user()?->can('create', [AttributeDefinition::class, $company]) === true,
            'filters' => ['search' => $search, 'scope' => $scope, 'type' => $type, 'status' => $status],
            'allTypes' => $allTypes,
            'totalCount' => AttributeDefinition::query()->forCompany($company)->count(),
            'visibleCount' => $definitions->total(),
            'hasActiveFilters' => $search !== '' || $scope !== 'all' || $type !== 'all' || $status !== 'all',
        ]);
    }

    public function create(CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize('create', [AttributeDefinition::class, $company]);

        return view('catalog.attributes.create', $this->formData($company));
    }

    public function store(StoreAttributeDefinitionRequest $request, CurrentCompany $currentCompany, CreateAttributeDefinitionAction $action): RedirectResponse
    {
        $definition = $action->execute($this->actor($request), $currentCompany->require(), $request->validated());

        return redirect()->route('catalog.attributes.show', $definition->uuid)->with('success', 'Attribute created.');
    }

    public function show(Request $request, CurrentCompany $currentCompany, string $attribute): View
    {
        $company = $currentCompany->require();
        $definition = $this->resolveDefinition($company, $attribute);
        $this->authorize('view', $definition);
        $definition->load(['options' => fn ($query) => $query->ordered(), 'createdBy', 'updatedBy'])
            ->loadCount(['productValues', 'variantValues']);

        return view('catalog.attributes.show', [
            'company' => $company,
            'definition' => $definition,
            'canManage' => $request->user()?->can('update', $definition) === true,
            'replacementDefinitions' => AttributeDefinition::query()
                ->forCompany($company)
                ->whereKeyNot($definition->getKey())
                ->where('status', AttributeDefinitionStatus::Active->value)
                ->where('type', $definition->type->value)
                ->where('scope', $definition->scope->value)
                ->ordered()
                ->get(),
        ]);
    }

    public function edit(CurrentCompany $currentCompany, string $attribute): View
    {
        $company = $currentCompany->require();
        $definition = $this->resolveDefinition($company, $attribute);
        $this->authorize('update', $definition);

        return view('catalog.attributes.edit', [...$this->formData($company), 'definition' => $definition]);
    }

    public function update(UpdateAttributeDefinitionRequest $request, CurrentCompany $currentCompany, UpdateAttributeDefinitionAction $action, string $attribute): RedirectResponse
    {
        $company = $currentCompany->require();
        $definition = $this->resolveDefinition($company, $attribute);
        $definition = $action->execute($this->actor($request), $company, $definition, $request->validated());

        return redirect()->route('catalog.attributes.show', $definition->uuid)->with('success', 'Attribute updated.');
    }

    public function archive(Request $request, CurrentCompany $currentCompany, ArchiveAttributeDefinitionAction $action, string $attribute): RedirectResponse
    {
        $company = $currentCompany->require();
        $definition = $this->resolveDefinition($company, $attribute);
        $this->authorize('archive', $definition);
        $action->execute($this->actor($request), $company, $definition);

        return back()->with('success', 'Attribute archived.');
    }

    public function restore(Request $request, CurrentCompany $currentCompany, RestoreAttributeDefinitionAction $action, string $attribute): RedirectResponse
    {
        $company = $currentCompany->require();
        $definition = $this->resolveDefinition($company, $attribute);
        $this->authorize('restore', $definition);
        $action->execute($this->actor($request), $company, $definition);

        return back()->with('success', 'Attribute restored.');
    }

    public function destroy(Request $request, CurrentCompany $currentCompany, DeleteAttributeAction $action, string $attribute): RedirectResponse
    {
        $company = $currentCompany->require();
        $definition = $this->resolveDefinition($company, $attribute);

        try {
            $action->execute($this->actor($request), $company, $definition);
        } catch (AttributeOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('catalog.attributes.index')->with('success', 'Attribute deleted.');
    }

    public function deprecate(
        Request $request,
        CurrentCompany $currentCompany,
        TaxonomyGovernanceAction $action,
        string $attribute,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $definition = $this->resolveDefinition($company, $attribute);
        $this->authorize('update', $definition);
        $validated = $request->validate(['replacement_uuid' => ['required', 'uuid']]);
        $replacement = $this->resolveDefinition($company, $validated['replacement_uuid']);
        $action->deprecateDefinition($this->actor($request), $company, $definition, $replacement);

        return back()->with('success', 'Attribute deprecated with a replacement.');
    }

    public function bulkDestroy(
        BulkDeleteAttributesRequest $request,
        CurrentCompany $currentCompany,
        BulkDeleteAttributesAction $action,
    ): RedirectResponse {
        $company = $currentCompany->require();

        $result = $action->execute(
            $this->actor($request),
            $company,
            $request->validated('attributes'),
        );

        if ($result['blocked'] !== []) {
            return back()->with('error', $this->blockedMessage('Deletion blocked.', $result['blocked']));
        }

        return redirect()->route('catalog.attributes.index')
            ->with('success', trans_choice(':count attribute deleted.|:count attributes deleted.', count($result['deleted']), [
                'count' => count($result['deleted']),
            ]));
    }

    private function resolveDefinition(Company $company, string $uuid): AttributeDefinition
    {
        return AttributeDefinition::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function formData(Company $company): array
    {
        return ['company' => $company, 'types' => AttributeDataType::cases(), 'scopes' => AttributeScope::cases()];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /**
     * @param  list<array{uuid: string, name: string, reason: string}>  $blocked
     */
    private function blockedMessage(string $heading, array $blocked): string
    {
        $lines = [$heading];

        foreach ($blocked as $item) {
            $lines[] = "{$item['name']}: {$item['reason']}";
        }

        return implode("\n", $lines);
    }
}
