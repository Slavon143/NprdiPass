<?php

namespace App\Domain\Invitations\Exceptions;

use DomainException;

class InvitationRegistrationUnavailable extends DomainException
{
    public function __construct(string $message = 'Registration is not available for this invitation.')
    {
        parent::__construct($message);
    }
}
