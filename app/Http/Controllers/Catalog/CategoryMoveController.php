<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Categories\MoveCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Categories\MoveCategoryRequest;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;

class CategoryMoveController extends Controller
{
    public function __invoke(
        MoveCategoryRequest $request,
        CurrentCompany $currentCompany,
        MoveCategoryAction $action,
        string $category,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $category = $this->category($company, $category);
        $parentUuid = $request->validated('parent_uuid');
        $parent = is_string($parentUuid) && $parentUuid !== ''
            ? $this->category($company, $parentUuid)
            : null;
        $action->execute($actor, $company, $category, $parent);

        return redirect()->route('catalog.categories.edit', $category->uuid)
            ->with('success', 'Category moved.');
    }

    private function category(Company $company, string $uuid): Category
    {
        return Category::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }
}
