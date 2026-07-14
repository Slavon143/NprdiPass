<?php

namespace App\Exceptions\Catalog;

use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ProductOperationException extends DomainException
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
        return new self('product_tenant_mismatch', 'product', 'Product belongs to another company.');
    }

    public static function slugConflict(?Throwable $previous = null): self
    {
        return new self('product_slug_conflict', 'slug', 'A product with this slug already exists.', $previous);
    }

    public static function primaryCategoryUnavailable(): self
    {
        return new self('primary_category_unavailable', 'primary_category_uuid', 'The selected primary category is unavailable.');
    }

    public static function categoriesUnavailable(): self
    {
        return new self('categories_unavailable', 'category_uuids', 'One or more selected categories are unavailable.');
    }

    public static function archivedCategory(): self
    {
        return new self('archived_category', 'category_uuids', 'Archived categories cannot be assigned.');
    }

    public static function tooManyCategories(): self
    {
        return new self('too_many_categories', 'category_uuids', 'Too many categories were selected.');
    }

    public static function invalid(string $field, string $message): self
    {
        return new self('invalid_product_data', $field, $message);
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The product operation could not be completed.',
                'errors' => [$this->field => [$this->getMessage()]],
            ], 422);
        }

        return back()->withErrors([$this->field => $this->getMessage()])->withInput();
    }
}
