<?php

namespace App\Services\Passports\Qr;

class PassportQrPayloadFactory
{
    public function create(string $publicId): string
    {
        $baseUrl = rtrim(config('passports.public_base_url'), '/');

        return "{$baseUrl}/p/{$publicId}";
    }
}
