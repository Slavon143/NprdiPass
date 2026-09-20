<?php

namespace App\Enums\Catalog;

enum AttributeOptionApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
