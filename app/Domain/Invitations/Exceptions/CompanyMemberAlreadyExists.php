<?php

namespace App\Domain\Invitations\Exceptions;

use DomainException;

class CompanyMemberAlreadyExists extends DomainException
{
    public function __construct()
    {
        parent::__construct('This email already belongs to a company member.');
    }
}
