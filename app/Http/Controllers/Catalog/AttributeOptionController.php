<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Attributes\ArchiveAttributeOptionAction;
use App\Actions\Catalog\Attributes\CreateAttributeOptionAction;
use App\Actions\Catalog\Attributes\ReorderAttributeOptionsAction;
use App\Actions\Catalog\Attributes\RestoreAttributeOptionAction;
use App\Actions\Catalog\Attributes\UpdateAttributeOptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Attributes\ReorderAttributeOptionsRequest;
use App\Http\Requests\Catalog\Attributes\StoreAttributeOptionRequest;
use App\Http\Requests\Catalog\Attributes\UpdateAttributeOptionRequest;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AttributeOptionController extends Controller
{
    public function store(StoreAttributeOptionRequest $request, CurrentCompany $currentCompany, CreateAttributeOptionAction $action, string $attribute): RedirectResponse
    {
        $company = $currentCompany->require();
        $definition = $this->definition($company, $attribute);
        $action->execute($this->actor($request), $company, $definition, $request->validated());

        return back()->with('success', 'Option created.');
    }

    public function update(UpdateAttributeOptionRequest $request, CurrentCompany $currentCompany, UpdateAttributeOptionAction $action, string $attribute, int $option): RedirectResponse
    {
        [$company, $definition, $option] = $this->context($currentCompany, $attribute, $option);
        $action->execute($this->actor($request), $company, $definition, $option, $request->validated());

        return back()->with('success', 'Option updated.');
    }

    public function archive(Request $request, CurrentCompany $currentCompany, ArchiveAttributeOptionAction $action, string $attribute, int $option): RedirectResponse
    {
        [$company, $definition, $option] = $this->context($currentCompany, $attribute, $option);
        $this->authorize('archive', $option);
        $action->execute($this->actor($request), $company, $definition, $option);

        return back()->with('success', 'Option archived.');
    }

    public function restore(Request $request, CurrentCompany $currentCompany, RestoreAttributeOptionAction $action, string $attribute, int $option): RedirectResponse
    {
        [$company, $definition, $option] = $this->context($currentCompany, $attribute, $option);
        $this->authorize('restore', $option);
        $action->execute($this->actor($request), $company, $definition, $option);

        return back()->with('success', 'Option restored.');
    }

    public function reorder(ReorderAttributeOptionsRequest $request, CurrentCompany $currentCompany, ReorderAttributeOptionsAction $action, string $attribute): RedirectResponse
    {
        $company = $currentCompany->require();
        $definition = $this->definition($company, $attribute);
        $ids = array_map('intval', $request->validated('option_ids'));
        $action->execute($this->actor($request), $company, $definition, $ids);

        return back()->with('success', 'Options reordered.');
    }

    /** @return array{Company, AttributeDefinition, AttributeOption} */
    private function context(CurrentCompany $currentCompany, string $uuid, int $optionId): array
    {
        $company = $currentCompany->require();
        $definition = $this->definition($company, $uuid);
        $option = AttributeOption::query()->forCompany($company)
            ->where('attribute_definition_id', $definition->getKey())
            ->whereKey($optionId)
            ->firstOrFail();

        return [$company, $definition, $option];
    }

    private function definition(Company $company, string $uuid): AttributeDefinition
    {
        return AttributeDefinition::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
