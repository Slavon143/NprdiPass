<?php

namespace App\Domain\Invitations\Exceptions;

use DomainException;

class InvitationCannotBeAccepted extends DomainException
{
    public function __construct(string $message = 'This invitation cannot be accepted.')
    {
        parent::__construct($message);
    }
}
