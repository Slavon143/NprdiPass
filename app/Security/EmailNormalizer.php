<?php

namespace App\Security;

class EmailNormalizer
{
    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
