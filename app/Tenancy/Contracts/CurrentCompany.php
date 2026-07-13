<?php

namespace App\Tenancy\Contracts;

use App\Models\Company;

interface CurrentCompany
{
    public function get(): ?Company;

    public function require(): Company;

    public function set(Company $company): void;

    public function clear(): void;

    public function has(): bool;
}
