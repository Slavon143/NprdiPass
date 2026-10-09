<?php

namespace App\Enums;

enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';
    case SupportAdmin = 'support_admin';
}
