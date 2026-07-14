<?php

namespace App\Exceptions\Catalog;

use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LifecycleOperationException extends DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidTransition(string $message = 'This lifecycle transition is not allowed.'): self
    {
        return new self('invalid_lifecycle_transition', $message);
    }

    public static function unavailable(): self
    {
        return new self('catalog_entity_unavailable', 'The selected catalog record is unavailable.');
    }

    public static function defaultVariant(): self
    {
        return new self('default_variant_archive_blocked', 'Select another default variant before archiving this variant.');
    }

    public static function lastVariant(): self
    {
        return new self('last_available_variant_archive_blocked', 'A product must keep at least one available variant.');
    }

    public static function archivedImmutable(): self
    {
        return new self('archived_catalog_record_read_only', 'Restore this archived record to draft before editing it.');
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The lifecycle operation could not be completed.',
                'errors' => ['lifecycle' => [$this->getMessage()]],
            ], 422);
        }

        return back()->withErrors(['lifecycle' => $this->getMessage()]);
    }
}
