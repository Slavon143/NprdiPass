<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Media\DeleteVariantMediaAction;
use App\Actions\Catalog\Media\ReorderVariantMediaAction;
use App\Actions\Catalog\Media\SetPrimaryVariantMediaAction;
use App\Actions\Catalog\Media\UpdateProductMediaAction;
use App\Actions\Catalog\Media\UploadVariantMediaAction;
use App\Http\Controllers\Catalog\Concerns\ResolvesCatalogMedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Media\ReorderVariantMediaRequest;
use App\Http\Requests\Catalog\Media\StoreVariantMediaRequest;
use App\Http\Requests\Catalog\Media\UpdateProductMediaRequest;
use App\Models\Catalog\ProductMedia;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VariantMediaController extends Controller
{
    use ResolvesCatalogMedia;

    public function index(Request $request, CurrentCompany $currentCompany, string $product, string $variant): View
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $this->authorize('view', $variant);
        $media = ProductMedia::query()->forCompany($company)
            ->where('product_id', $product->getKey())->where('product_variant_id', $variant->getKey())->ordered()->with('uploadedBy:id,name')->get();

        return view('catalog.products.variants.media.index', ['company' => $company, 'product' => $product, 'variant' => $variant, 'media' => $media, 'canManage' => $request->user()?->can('manageMedia', $variant) === true, 'limits' => config('catalog.media')]);
    }

    public function store(StoreVariantMediaRequest $request, CurrentCompany $currentCompany, UploadVariantMediaAction $action, string $product, string $variant): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $v = $request->validated();
        $action->execute($this->actor($request), $company, $product, $variant, $request->file('image'), $v['alt_text'] ?? null, $v['caption'] ?? null, (bool) ($v['make_primary'] ?? false), $v['sort_order'] ?? null);

        return back()->with('success', 'Variant image uploaded.');
    }

    public function update(UpdateProductMediaRequest $request, CurrentCompany $currentCompany, UpdateProductMediaAction $action, string $product, string $variant, string $media): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $media = $this->resolveMedia($company, $product, $media, $variant);
        $action->executeVariant($this->actor($request), $company, $product, $variant, $media, $request->validated());

        return back()->with('success', 'Image metadata updated.');
    }

    public function setPrimary(Request $request, CurrentCompany $currentCompany, SetPrimaryVariantMediaAction $action, string $product, string $variant, string $media): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $media = $this->resolveMedia($company, $product, $media, $variant);
        $this->authorize('setPrimary', $media);
        $action->execute($this->actor($request), $company, $product, $variant, $media);

        return back()->with('success', 'Primary Variant image updated.');
    }

    public function reorder(ReorderVariantMediaRequest $request, CurrentCompany $currentCompany, ReorderVariantMediaAction $action, string $product, string $variant): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $uuids = $request->validated('media_uuids');
        $action->execute($this->actor($request), $company, $product, $variant, is_array($uuids) ? array_values($uuids) : []);

        return back()->with('success', 'Variant images reordered.');
    }

    public function destroy(Request $request, CurrentCompany $currentCompany, DeleteVariantMediaAction $action, string $product, string $variant, string $media): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $media = $this->resolveMedia($company, $product, $media, $variant);
        $this->authorize('delete', $media);
        $action->execute($this->actor($request), $company, $product, $variant, $media);

        return back()->with('success', 'Variant image deleted.');
    }
}
