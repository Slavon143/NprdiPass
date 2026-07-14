<?php

namespace App\Exceptions\Catalog;

use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AttributeOperationException extends DomainException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $field,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalid(string $field, string $message): self
    {
        return new self('invalid_attribute_data', $field, $message);
    }

    public static function tenantMismatch(): self
    {
        return new self('attribute_tenant_mismatch', 'attribute', 'The selected attribute is unavailable.');
    }

    public static function optionMismatch(): self
    {
        return new self('attribute_option_mismatch', 'option', 'The selected option is unavailable for this attribute.');
    }

    public static function codeConflict(string $field = 'code', ?Throwable $previous = null): self
    {
        return new self('attribute_code_conflict', $field, 'This code is already in use.', $previous);
    }

    public static function immutable(string $field, string $message): self
    {
        return new self('attribute_field_immutable', $field, $message);
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The attribute operation could not be completed.',
                'errors' => [$this->field => [$this->getMessage()]],
            ], 422);
        }

        return back()->withErrors([$this->field => $this->getMessage()])->withInput();
    }
}
