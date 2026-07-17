<?php

namespace App\Services\Passports\Publication;

use App\Models\Passports\ProductPassport;
use Illuminate\Support\Facades\DB;

class PassportVersionNumberAllocator
{
    public function allocate(ProductPassport $passport): int
    {
        $max = DB::table('product_passport_versions')
            ->where('passport_id', $passport->id)
            ->lockForUpdate()
            ->max('version_number');

        return ($max ?? 0) + 1;
    }
}
