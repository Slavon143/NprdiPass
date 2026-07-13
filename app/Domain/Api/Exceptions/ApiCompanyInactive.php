<?php

namespace App\Domain\Api\Exceptions;

use RuntimeException;

class ApiCompanyInactive extends RuntimeException
{
    public function __construct(
        public readonly int $status,
    ) {
        parent::__construct('The token company is not active.');
    }
}
