<?php

namespace App\Domain\Companies\Exceptions;

use DomainException;

class CannotRemoveOwnCompanyMembership extends DomainException
{
    public function __construct()
    {
        parent::__construct('Self-removal requires the dedicated company leave flow.');
    }
}
