<?php

namespace App\Events\Passports;

use App\Models\Passports\ProductPassport;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductPassportArchived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProductPassport $passport,
        public User $actor,
    ) {}
}
