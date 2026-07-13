<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';
}
