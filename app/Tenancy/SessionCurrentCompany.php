<?php

namespace App\Tenancy;

use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\Exceptions\CurrentCompanyNotSet;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;

class SessionCurrentCompany implements CurrentCompany
{
    public function __construct(
        private readonly Session $session,
        private readonly AuthFactory $auth,
    ) {}

    public function get(): ?Company
    {
        $companyId = $this->companyIdFromSession();

        if ($companyId === null) {
            return null;
        }

        $user = $this->auth->guard()->user();

        if (! $user instanceof User || $user->trashed()) {
            $this->clear();

            return null;
        }

        $company = $user->companies()
            ->where('companies.id', $companyId)
            ->first();

        if ($company === null) {
            $this->clear();

            return null;
        }

        return $company;
    }

    public function require(): Company
    {
        return $this->get() ?? throw new CurrentCompanyNotSet;
    }

    public function set(Company $company): void
    {
        $companyId = $company->getKey();

        if (! is_int($companyId) || $companyId < 1) {
            throw new InvalidArgumentException('Current company must be a persisted model.');
        }

        $this->session->put($this->sessionKey(), $companyId);
    }

    public function clear(): void
    {
        $this->session->forget($this->sessionKey());
    }

    public function has(): bool
    {
        return $this->get() !== null;
    }

    private function companyIdFromSession(): ?int
    {
        $companyId = $this->session->get($this->sessionKey());

        if (is_int($companyId) && $companyId > 0) {
            return $companyId;
        }

        if (is_string($companyId) && ctype_digit($companyId) && (int) $companyId > 0) {
            return (int) $companyId;
        }

        if ($companyId !== null) {
            $this->clear();
        }

        return null;
    }

    private function sessionKey(): string
    {
        return (string) config('tenancy.session_key');
    }
}
