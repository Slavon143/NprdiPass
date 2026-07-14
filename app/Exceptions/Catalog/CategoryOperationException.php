<?php

namespace App\Exceptions\Catalog;

use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CategoryOperationException extends DomainException
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
        return new self('category_tenant_mismatch', 'category', 'Category belongs to another company.');
    }

    public static function parentUnavailable(string $message = 'The selected parent category is unavailable.'): self
    {
        return new self('category_parent_unavailable', 'parent_uuid', $message);
    }

    public static function cycle(string $message = 'Category cannot be moved into itself or one of its descendants.'): self
    {
        return new self('category_cycle_detected', 'parent_uuid', $message);
    }

    public static function depthExceeded(): self
    {
        return new self('category_depth_exceeded', 'parent_uuid', 'Maximum category depth exceeded.');
    }

    public static function slugConflict(?Throwable $previous = null): self
    {
        return new self('category_slug_conflict', 'slug', 'Slug already exists.', $previous);
    }

    public static function limitExceeded(): self
    {
        return new self('category_limit_exceeded', 'name', 'The company category limit has been reached.');
    }

    public static function archiveBlocked(string $message): self
    {
        return new self('category_archive_blocked', 'category', $message);
    }

    public static function invalidReorder(string $message): self
    {
        return new self('invalid_category_reorder', 'ordered_uuids', $message);
    }

    public static function invalid(string $field, string $message): self
    {
        return new self('invalid_category_data', $field, $message);
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The category operation could not be completed.',
                'errors' => [$this->field => [$this->getMessage()]],
            ], 422);
        }

        return back()->withErrors([$this->field => $this->getMessage()])->withInput();
    }
}
