<?php

namespace App\Http\Controllers\Api\V1\Catalog\Concerns;

use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;

trait AuthorizesCatalogApi
{
    protected function authorizeProductViewAny(Company $company): void
    {
        if (! request()->user()?->can('viewAny', [Product::class, $company])) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeProductView(Product $product): void
    {
        if (! request()->user()?->can('view', $product)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeProductCreate(Company $company): void
    {
        if (! request()->user()?->can('create', [Product::class, $company])) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeProductUpdate(Product $product): void
    {
        if (! request()->user()?->can('update', $product)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeCategoryViewAny(Company $company): void
    {
        if (! request()->user()?->can('viewAny', [Category::class, $company])) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeCategoryManage(Company $company): void
    {
        if (! request()->user()?->can('create', [Category::class, $company])) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeCategoryView(Category $category): void
    {
        if (! request()->user()?->can('view', $category)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeCategoryUpdate(Category $category): void
    {
        if (! request()->user()?->can('update', $category)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeCategoryMove(Category $category): void
    {
        if (! request()->user()?->can('move', $category)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeCategoryArchive(Category $category): void
    {
        if (! request()->user()?->can('archive', $category)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeCategoryRestore(Category $category): void
    {
        if (! request()->user()?->can('restore', $category)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeVariantView(ProductVariant $variant): void
    {
        if (! request()->user()?->can('view', $variant)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeVariantCreate(Product $product): void
    {
        if (! request()->user()?->can('create', [ProductVariant::class, $product])) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeVariantUpdate(ProductVariant $variant): void
    {
        if (! request()->user()?->can('update', $variant)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeVariantSetDefault(ProductVariant $variant): void
    {
        if (! request()->user()?->can('setDefault', $variant)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeVariantArchive(ProductVariant $variant): void
    {
        if (! request()->user()?->can('archive', $variant)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeVariantRestore(ProductVariant $variant): void
    {
        if (! request()->user()?->can('restore', $variant)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeProductActivate(Product $product): void
    {
        if (! request()->user()?->can('activate', $product)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeProductReturnToDraft(Product $product): void
    {
        if (! request()->user()?->can('returnToDraft', $product)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeProductArchive(Product $product): void
    {
        if (! request()->user()?->can('archive', $product)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeProductRestore(Product $product): void
    {
        if (! request()->user()?->can('restore', $product)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeProductManageAttributes(Product $product): void
    {
        if (! request()->user()?->can('manageAttributes', $product)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeVariantManageAttributes(ProductVariant $variant): void
    {
        if (! request()->user()?->can('manageAttributes', $variant)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeAttributeViewAny(Company $company): void
    {
        if (! request()->user()?->can('viewAny', [AttributeDefinition::class, $company])) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeAttributeManage(Company $company): void
    {
        if (! request()->user()?->can('create', [AttributeDefinition::class, $company])) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeAttributeView(AttributeDefinition $definition): void
    {
        if (! request()->user()?->can('view', $definition)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeAttributeUpdate(AttributeDefinition $definition): void
    {
        if (! request()->user()?->can('update', $definition)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeAttributeArchive(AttributeDefinition $definition): void
    {
        if (! request()->user()?->can('archive', $definition)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeAttributeRestore(AttributeDefinition $definition): void
    {
        if (! request()->user()?->can('restore', $definition)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeOptionManage(AttributeDefinition $definition): void
    {
        if (! request()->user()?->can('manageOptions', $definition)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeOptionView(AttributeOption $option): void
    {
        if (! request()->user()?->can('view', $option)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeOptionUpdate(AttributeOption $option): void
    {
        if (! request()->user()?->can('update', $option)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeOptionArchive(AttributeOption $option): void
    {
        if (! request()->user()?->can('archive', $option)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeOptionRestore(AttributeOption $option): void
    {
        if (! request()->user()?->can('restore', $option)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeMediaView(ProductMedia $media): void
    {
        if (! request()->user()?->can('view', $media)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeProductMediaManage(Product $product): void
    {
        if (! request()->user()?->can('manageMedia', $product)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeMediaSetPrimary(ProductMedia $media): void
    {
        if (! request()->user()?->can('setPrimary', $media)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeMediaDelete(ProductMedia $media): void
    {
        if (! request()->user()?->can('delete', $media)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeProductViewReadiness(Product $product): void
    {
        if (! request()->user()?->can('viewReadiness', $product)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeDocumentViewAny(Company $company): void
    {
        if (! request()->user()?->can('viewAny', [ProductDocument::class, $company])) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeDocumentView(ProductDocument $document): void
    {
        if (! request()->user()?->can('view', $document)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeDocumentCreate(Company $company): void
    {
        if (! request()->user()?->can('create', [ProductDocument::class, $company])) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeDocumentManage(ProductDocument $document): void
    {
        if (! request()->user()?->can('addVersion', $document)) {
            throw new AuthorizationException;
        }
    }

    protected function authorizeDocumentDownload(ProductDocument $document): void
    {
        if (! request()->user()?->can('download', $document)) {
            throw new AuthorizationException;
        }
    }
}
