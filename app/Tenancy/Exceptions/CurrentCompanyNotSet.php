<?php

namespace App\Tenancy\Exceptions;

use DomainException;

class CurrentCompanyNotSet extends DomainException
{
    public function __construct()
    {
        parent::__construct('Current company is not selected.');
    }
}
