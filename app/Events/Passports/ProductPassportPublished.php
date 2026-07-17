<?php

namespace App\Events\Passports;

use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductPassportPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProductPassport $passport,
        public ProductPassportVersion $publishedVersion,
        public User $actor,
    ) {}
}
