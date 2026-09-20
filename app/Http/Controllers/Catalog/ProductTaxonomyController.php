<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Taxonomy\TaxonomyGovernanceAction;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductTaxonomyController extends Controller
{
    public function acknowledge(
        Request $request,
        CurrentCompany $currentCompany,
        TaxonomyGovernanceAction $action,
        string $product,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $product = Product::query()->forCompany($company)->where('uuid', $product)->firstOrFail();
        $this->authorize('update', $product);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->acknowledgeProduct($actor, $company, $product);

        return back()->with('success', 'Category taxonomy review acknowledged.');
    }
}
