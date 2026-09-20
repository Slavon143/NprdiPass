<?php

namespace App\Enums\Fortnox;

enum FortnoxConflictStatus: string
{
    case Open = 'open';
    case ResolvedFortnox = 'resolved_fortnox';
    case ResolvedNordipass = 'resolved_nordipass';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::ResolvedFortnox => 'Fortnox value applied',
            self::ResolvedNordipass => 'NordiPass value kept',
        };
    }
}
