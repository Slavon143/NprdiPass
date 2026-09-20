<?php

namespace App\Enums\Jobs;

enum JobStatusState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case CancelRequested = 'cancel_requested';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
}
