<?php

namespace App\Actions\Catalog\Exceptions;

use RuntimeException;

class CatalogIdentifierConflict extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('A catalog identifier is already in use.', 0, $previous);
    }
}
