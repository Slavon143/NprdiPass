<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Products\SetProductManagedAction;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductManagedController extends Controller
{
    public function update(Request $request, CurrentCompany $currentCompany, SetProductManagedAction $action, string $product): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = Product::query()->forCompany($company)->where('uuid', $product)->firstOrFail();
        $this->authorize('update', $product);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = $request->validate(['managed' => ['required', 'boolean']]);
        $updated = $action->execute($actor, $company, $product, (bool) $data['managed']);

        return redirect()->route('catalog.products.show', $updated->uuid)->with('success',
            $updated->managed ? 'Product enrolled in DPP.' : 'Product removed from DPP management. Published history is retained.');
    }
}
