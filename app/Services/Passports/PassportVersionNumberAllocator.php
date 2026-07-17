<?php

namespace App\Services\Passports;

use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;

class PassportVersionNumberAllocator
{
    public function allocate(ProductPassport $passport): int
    {
        $max = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->max('version_number');

        return ($max ?? 0) + 1;
    }
}
