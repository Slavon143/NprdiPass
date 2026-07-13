<?php

namespace App\Enums;

enum CompanyRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';
}
