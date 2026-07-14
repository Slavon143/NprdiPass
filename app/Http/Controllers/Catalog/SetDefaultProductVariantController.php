<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\SetDefaultProductVariantAction;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SetDefaultProductVariantController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentCompany $currentCompany,
        SetDefaultProductVariantAction $action,
        string $product,
        string $variant,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $product = Product::query()->forCompany($company)->where('uuid', $product)->firstOrFail();
        $variant = ProductVariant::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->where('uuid', $variant)
            ->firstOrFail();
        $this->authorize('setDefault', $variant);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($actor, $product, $variant);

        return back()->with('success', 'Default variant updated.');
    }
}
