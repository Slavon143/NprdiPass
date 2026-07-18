<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Attributes\ArchiveAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\BulkDeleteAttributesAction;
use App\Actions\Catalog\Attributes\CreateAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\DeleteAttributeAction;
use App\Actions\Catalog\Attributes\RestoreAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\UpdateAttributeDefinitionAction;
use App\Enums\Catalog\AttributeDataType;
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

        return view('catalog.attributes.index', [
            'company' => $company,
            'definitions' => AttributeDefinition::query()->forCompany($company)
                ->withCount(['options', 'productValues', 'variantValues'])
                ->ordered()
                ->paginate(50),
            'canManage' => $request->user()?->can('create', [AttributeDefinition::class, $company]) === true,
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
