<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Media\DeleteProductMediaAction;
use App\Actions\Catalog\Media\ReorderProductMediaAction;
use App\Actions\Catalog\Media\SetPrimaryProductMediaAction;
use App\Actions\Catalog\Media\UpdateProductMediaAction;
use App\Actions\Catalog\Media\UploadProductMediaAction;
use App\Http\Controllers\Catalog\Concerns\ResolvesCatalogMedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Media\ReorderProductMediaRequest;
use App\Http\Requests\Catalog\Media\StoreProductMediaRequest;
use App\Http\Requests\Catalog\Media\UpdateProductMediaRequest;
use App\Models\Catalog\ProductMedia;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductMediaController extends Controller
{
    use ResolvesCatalogMedia;

    public function index(Request $request, CurrentCompany $currentCompany, string $product): View
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $this->authorize('view', $product);
        $media = ProductMedia::query()->forCompany($company)
            ->where('product_id', $product->getKey())->productLevel()->ordered()->with('uploadedBy:id,name')->get();

        return view('catalog.products.media.index', ['company' => $company, 'product' => $product, 'media' => $media, 'canManage' => $request->user()?->can('manageMedia', $product) === true, 'limits' => config('catalog.media')]);
    }

    public function store(StoreProductMediaRequest $request, CurrentCompany $currentCompany, UploadProductMediaAction $action, string $product): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $v = $request->validated();
        $action->execute($this->actor($request), $company, $product, $request->file('image'), $v['alt_text'] ?? null, $v['caption'] ?? null, (bool) ($v['make_primary'] ?? false), $v['sort_order'] ?? null);

        return back()->with('success', 'Product image uploaded.');
    }

    public function update(UpdateProductMediaRequest $request, CurrentCompany $currentCompany, UpdateProductMediaAction $action, string $product, string $media): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $media = $this->resolveMedia($company, $product, $media);
        $action->executeProduct($this->actor($request), $company, $product, $media, $request->validated());

        return back()->with('success', 'Image metadata updated.');
    }

    public function setPrimary(Request $request, CurrentCompany $currentCompany, SetPrimaryProductMediaAction $action, string $product, string $media): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $media = $this->resolveMedia($company, $product, $media);
        $this->authorize('setPrimary', $media);
        $action->execute($this->actor($request), $company, $product, $media);

        return back()->with('success', 'Primary Product image updated.');
    }

    public function reorder(ReorderProductMediaRequest $request, CurrentCompany $currentCompany, ReorderProductMediaAction $action, string $product): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $uuids = $request->validated('media_uuids');
        $action->execute($this->actor($request), $company, $product, is_array($uuids) ? array_values($uuids) : []);

        return back()->with('success', 'Product images reordered.');
    }

    public function destroy(Request $request, CurrentCompany $currentCompany, DeleteProductMediaAction $action, string $product, string $media): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $media = $this->resolveMedia($company, $product, $media);
        $this->authorize('delete', $media);
        $action->execute($this->actor($request), $company, $product, $media);

        return back()->with('success', 'Product image deleted.');
    }
}
