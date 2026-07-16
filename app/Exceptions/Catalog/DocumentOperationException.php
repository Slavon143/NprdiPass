<?php

namespace App\Exceptions\Catalog;

use Exception;

class DocumentOperationException extends Exception
{
    public static function invalid(string $resource, string $message, string $errorCode = 'document_error'): self
    {
        return new self("{$resource}: {$message}");
    }
}
