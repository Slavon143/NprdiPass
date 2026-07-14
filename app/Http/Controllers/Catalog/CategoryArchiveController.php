<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Categories\ArchiveCategoryAction;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Category;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CategoryArchiveController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentCompany $currentCompany,
        ArchiveCategoryAction $action,
        string $category,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $category = Category::query()->forCompany($company)->where('uuid', $category)->firstOrFail();
        $this->authorize('archive', $category);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($actor, $company, $category);

        return redirect()->route('catalog.categories.index')->with('success', 'Category archived.');
    }
}
