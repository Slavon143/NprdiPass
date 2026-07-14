<?php

namespace App\Exceptions\Catalog;

use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MediaOperationException extends DomainException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $field, string $message, string $code = 'invalid_media'): self
    {
        return new self($code, $field, $message);
    }

    public static function unavailable(): self
    {
        return new self('media_unavailable', 'media', 'The selected image is unavailable.');
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The media operation could not be completed.',
                'errors' => [$this->field => [$this->getMessage()]],
            ], 422);
        }

        return back()->withErrors([$this->field => $this->getMessage()])->withInput();
    }
}
