<?php

namespace App\Enums\Approvals;

enum PassportPublicationApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
}
