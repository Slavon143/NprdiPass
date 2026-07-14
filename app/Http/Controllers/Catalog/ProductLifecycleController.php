<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Lifecycle\ActivateProductAction;
use App\Actions\Catalog\Lifecycle\ArchiveProductAction;
use App\Actions\Catalog\Lifecycle\RestoreProductAction;
use App\Actions\Catalog\Lifecycle\ReturnProductToDraftAction;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductLifecycleController extends Controller
{
    public function activate(Request $request, CurrentCompany $currentCompany, ActivateProductAction $action, string $product): RedirectResponse
    {
        return $this->run($request, $currentCompany, $product, 'activate', $action, 'Product activated.');
    }

    public function returnToDraft(Request $request, CurrentCompany $currentCompany, ReturnProductToDraftAction $action, string $product): RedirectResponse
    {
        return $this->run($request, $currentCompany, $product, 'returnToDraft', $action, 'Product returned to draft.');
    }

    public function archive(Request $request, CurrentCompany $currentCompany, ArchiveProductAction $action, string $product): RedirectResponse
    {
        return $this->run($request, $currentCompany, $product, 'archive', $action, 'Product archived.');
    }

    public function restore(Request $request, CurrentCompany $currentCompany, RestoreProductAction $action, string $product): RedirectResponse
    {
        return $this->run($request, $currentCompany, $product, 'restore', $action, 'Product restored to draft.');
    }

    private function run(
        Request $request,
        CurrentCompany $currentCompany,
        string $uuid,
        string $ability,
        ActivateProductAction|ReturnProductToDraftAction|ArchiveProductAction|RestoreProductAction $action,
        string $message,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $uuid);
        $this->authorize($ability, $product);
        /** @var Product $updated */
        $updated = $action->execute($this->actor($request), $company, $product);

        return redirect()->route('catalog.products.show', $updated->uuid)->with('success', $message);
    }

    private function resolveProduct(Company $company, string $uuid): Product
    {
        return Product::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
