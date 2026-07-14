<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Lifecycle\ArchiveProductVariantAction;
use App\Actions\Catalog\Lifecycle\RestoreProductVariantAction;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductVariantLifecycleController extends Controller
{
    public function archive(
        Request $request,
        CurrentCompany $currentCompany,
        ArchiveProductVariantAction $action,
        string $product,
        string $variant,
    ): RedirectResponse {
        return $this->run($request, $currentCompany, $action, $product, $variant, 'archive', 'Variant archived.');
    }

    public function restore(
        Request $request,
        CurrentCompany $currentCompany,
        RestoreProductVariantAction $action,
        string $product,
        string $variant,
    ): RedirectResponse {
        return $this->run($request, $currentCompany, $action, $product, $variant, 'restore', 'Variant restored.');
    }

    private function run(
        Request $request,
        CurrentCompany $currentCompany,
        ArchiveProductVariantAction|RestoreProductVariantAction $action,
        string $productUuid,
        string $variantUuid,
        string $ability,
        string $message,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $product = Product::query()->forCompany($company)->where('uuid', $productUuid)->firstOrFail();
        $variant = ProductVariant::query()->forCompany($company)
            ->where('product_id', $product->getKey())->where('uuid', $variantUuid)->firstOrFail();
        $this->authorize($ability, $variant);
        /** @var ProductVariant $updated */
        $updated = $action->execute($this->actor($request), $company, $product, $variant);

        return redirect()->route('catalog.products.variants.show', [$product->uuid, $updated->uuid])->with('success', $message);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
