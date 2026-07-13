<?php

namespace App\Tenancy;

use App\Models\Company;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\Exceptions\CurrentCompanyNotSet;
use InvalidArgumentException;

class TokenCurrentCompany implements CurrentCompany
{
    private ?Company $company = null;

    public function get(): ?Company
    {
        return $this->company;
    }

    public function require(): Company
    {
        return $this->company ?? throw new CurrentCompanyNotSet;
    }

    public function set(Company $company): void
    {
        if (! $company->exists || ! is_int($company->getKey())) {
            throw new InvalidArgumentException('Current company must be a persisted model.');
        }

        $this->company = $company;
    }

    public function clear(): void
    {
        $this->company = null;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }
}
