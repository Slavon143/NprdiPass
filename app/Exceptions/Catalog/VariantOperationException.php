<?php

namespace App\Exceptions\Catalog;

use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VariantOperationException extends DomainException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $field,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function tenantMismatch(): self
    {
        return new self('variant_tenant_mismatch', 'variant', 'The selected variant is unavailable.');
    }

    public static function productMismatch(): self
    {
        return new self('variant_product_mismatch', 'variant', 'The selected variant does not belong to this product.');
    }

    public static function skuConflict(?Throwable $previous = null): self
    {
        return new self('duplicate_sku', 'sku', 'A variant with this SKU already exists in this company.', $previous);
    }

    public static function gtinConflict(?Throwable $previous = null): self
    {
        return new self('duplicate_gtin', 'gtin', 'This GTIN is already assigned to another variant.', $previous);
    }

    public static function limitReached(): self
    {
        return new self('variant_limit_reached', 'variant', 'The maximum number of variants has been reached.');
    }

    public static function invalid(string $field, string $message): self
    {
        return new self('invalid_variant_data', $field, $message);
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The variant operation could not be completed.',
                'errors' => [$this->field => [$this->getMessage()]],
            ], 422);
        }

        return back()->withErrors([$this->field => $this->getMessage()])->withInput();
    }
}
