<?php

namespace App\Domain\Companies\Exceptions;

use DomainException;

class LastCompanyOwnerCannotBeRemoved extends DomainException
{
    public function __construct()
    {
        parent::__construct('The last company owner cannot be removed or downgraded.');
    }
}
