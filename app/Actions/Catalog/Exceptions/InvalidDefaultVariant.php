<?php

namespace App\Actions\Catalog\Exceptions;

use DomainException;

class InvalidDefaultVariant extends DomainException
{
    public function __construct()
    {
        parent::__construct('The selected variant cannot be the default for this product.');
    }
}
