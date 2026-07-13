<?php

namespace App\Domain\Invitations\Exceptions;

use DomainException;

class InvitationCannotBeResent extends DomainException
{
    public function __construct()
    {
        parent::__construct('Only a pending invitation can be resent.');
    }
}
