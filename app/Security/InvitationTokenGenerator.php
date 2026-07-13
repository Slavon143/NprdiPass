<?php

namespace App\Security;

class InvitationTokenGenerator
{
    public function generate(): InvitationToken
    {
        $plainText = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return new InvitationToken(
            $plainText,
            hash('sha256', $plainText),
        );
    }
}
