<?php

namespace App\Exceptions\Billing;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CommercialReadOnlyException extends ValidationException
{
    public function __construct()
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('entitlement', 'This company is read-only. Choose an active subscription to resume changes.');
        parent::__construct($validator);
    }
}
