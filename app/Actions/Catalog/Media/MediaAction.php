<?php

namespace App\Actions\Catalog\Media;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Exceptions\Catalog\MediaOperationException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

abstract class MediaAction
{
    public function __construct(
        protected readonly CompanyAuthorizer $authorizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    protected function authorize(User $actor, Company $company, CompanyPermission $permission = CompanyPermission::CatalogManageMedia): Company
    {
        $company = Company::query()->find($company->getKey());

        if ($company?->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $company, $permission);

        return $company;
    }

    protected function assertProduct(Company $company, Product $product): void
    {
        if ((int) $product->company_id !== (int) $company->getKey() || $product->trashed()) {
            throw MediaOperationException::unavailable();
        }
    }

    protected function assertVariant(Company $company, Product $product, ProductVariant $variant): void
    {
        if ((int) $variant->company_id !== (int) $company->getKey()
            || (int) $variant->product_id !== (int) $product->getKey() || $variant->trashed()) {
            throw MediaOperationException::unavailable();
        }
    }

    protected function assertProductMedia(Company $company, Product $product, ProductMedia $media): void
    {
        if ((int) $media->company_id !== (int) $company->getKey()
            || (int) $media->product_id !== (int) $product->getKey()
            || $media->product_variant_id !== null || $media->trashed()) {
            throw MediaOperationException::unavailable();
        }
    }

    protected function assertVariantMedia(Company $company, Product $product, ProductVariant $variant, ProductMedia $media): void
    {
        if ((int) $media->company_id !== (int) $company->getKey()
            || (int) $media->product_id !== (int) $product->getKey()
            || (int) $media->product_variant_id !== (int) $variant->getKey() || $media->trashed()) {
            throw MediaOperationException::unavailable();
        }
    }

    protected function nullableText(mixed $value, string $field): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw MediaOperationException::invalid($field, "The {$field} field must be text.");
        }

        $value = trim((string) $value);
        $maximum = (int) config('catalog.media.'.($field === 'alt_text' ? 'alt_text_max' : 'caption_max'));

        if (mb_strlen($value) > $maximum) {
            throw MediaOperationException::invalid($field, "The {$field} field may not exceed {$maximum} characters.");
        }

        return $value === '' ? null : $value;
    }

    protected function sortOrder(mixed $value, int $fallback): int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 0 || $value > 4294967295) {
            throw MediaOperationException::invalid('sort_order', 'The sort order must be a non-negative integer.');
        }

        return $value;
    }
}
