<?php

namespace App\Enums\Fortnox;

enum FortnoxConnectionStatus: string
{
    case Active = 'active';
    case ReconnectRequired = 'reconnect_required';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Connected',
            self::ReconnectRequired => 'Re-authentication required',
            self::Revoked => 'Disconnected',
        };
    }
}
