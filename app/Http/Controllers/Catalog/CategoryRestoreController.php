<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Categories\RestoreCategoryAction;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Category;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CategoryRestoreController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentCompany $currentCompany,
        RestoreCategoryAction $action,
        string $category,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $category = Category::query()->forCompany($company)->where('uuid', $category)->firstOrFail();
        $this->authorize('restore', $category);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($actor, $company, $category);

        return redirect()->route('catalog.categories.edit', $category->uuid)
            ->with('success', 'Category restored.');
    }
}
