<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Categories\ReorderSiblingCategoriesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Categories\ReorderCategoriesRequest;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;

class CategoryReorderController extends Controller
{
    public function __invoke(
        ReorderCategoriesRequest $request,
        CurrentCompany $currentCompany,
        ReorderSiblingCategoriesAction $action,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validated();
        $parentUuid = $validated['parent_uuid'] ?? null;
        $parent = is_string($parentUuid) && $parentUuid !== ''
            ? $this->category($company, $parentUuid)
            : null;
        /** @var list<string> $orderedUuids */
        $orderedUuids = $validated['ordered_uuids'];
        $action->execute($actor, $company, $parent, $orderedUuids);

        return back()->with('success', 'Category order updated.');
    }

    private function category(Company $company, string $uuid): Category
    {
        return Category::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }
}
