<?php

namespace App\Enums\Imports;

enum ImportJobStatus: string
{
    case Uploaded = 'uploaded';
    case Previewed = 'previewed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
