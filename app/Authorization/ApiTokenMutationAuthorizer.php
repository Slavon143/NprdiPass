<?php

namespace App\Authorization;

use App\Domain\Api\Exceptions\ApiCompanyInactive;
use App\Domain\Api\Exceptions\ApiTokenAbilityMissing;
use App\Domain\Api\Exceptions\ApiTokenExpired;
use App\Domain\Api\Exceptions\ApiTokenInvalid;
use App\Enums\ApiTokenAbility;
use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Billing\CommercialAccessPolicy;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

class ApiTokenMutationAuthorizer
{
    private ?PersonalAccessToken $token = null;

    /** @var list<string> */
    private array $abilities = [];

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $next
     * @return TResult
     */
    public function withToken(PersonalAccessToken $token, string $ability, Closure $next): mixed
    {
        $previousToken = $this->token;
        $previousAbilities = $this->abilities;
        if ($previousToken !== null && $previousToken->getKey() !== $token->getKey()) {
            throw new ApiTokenInvalid;
        }
        $this->token = clone $token;
        $this->abilities = array_values(array_unique([...$previousAbilities, $ability]));
        try {
            return $next();
        } finally {
            $this->token = $previousToken;
            $this->abilities = $previousAbilities;
        }
    }

    public function authorizeIfBound(User $actor, Company $company, bool $sharedCompany = false): void
    {
        // Scalar-identity workers load a fresh actor without an HTTP token,
        // including when the sync queue executes inside the originating request.
        if ($this->token !== null && $actor->currentAccessToken() instanceof PersonalAccessToken) {
            $this->authorize($actor, $company, sharedCompany: $sharedCompany);
        }
    }

    public function authorize(User $actor, Company $company, ?string $ability = null, bool $sharedCompany = false): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('API mutation authorization requires a transaction.');
        }
        $expected = $this->token ?? $actor->currentAccessToken();
        if (! $expected instanceof PersonalAccessToken || $expected->company_id !== $company->getKey()) {
            throw new ApiTokenInvalid;
        }

        // Match the existing Company-first protocol. Current reads bypass an
        // earlier RR snapshot and hold revocation boundaries until this commit.
        $companies = Company::query()->whereKey($company->getKey());
        $currentCompany = ($sharedCompany ? $companies->sharedLock() : $companies->lockForUpdate())->first(['id', 'status', 'commercial_model_version']);
        if ($currentCompany === null) {
            throw new ApiTokenInvalid;
        }
        if ($currentCompany->status !== CompanyStatus::Active) {
            throw new ApiCompanyInactive($currentCompany->status === CompanyStatus::Suspended ? 423 : 403);
        }
        $currentUser = User::query()->whereKey($actor->getKey())->sharedLock()->first(['id', 'status']);
        if ($currentUser?->status !== UserStatus::Active) {
            throw new ApiTokenInvalid;
        }
        $membership = CompanyMembership::query()->where('company_id', $company->getKey())
            ->where('user_id', $actor->getKey())->sharedLock()->first(['id']);
        if ($membership === null) {
            throw new AuthorizationException;
        }
        $current = PersonalAccessToken::query()->whereKey($expected->getKey())
            ->where('company_id', $company->getKey())
            ->where('tokenable_id', $actor->getKey())
            ->where('tokenable_type', $actor->getMorphClass())
            ->sharedLock()->first();
        if ($current === null || ! hash_equals((string) $expected->token, (string) $current->token)) {
            throw new ApiTokenInvalid;
        }
        if ($current->isExpired()) {
            throw new ApiTokenExpired;
        }
        $abilities = $ability === null ? $this->abilities : [...$this->abilities, $ability];
        if ($abilities === []) {
            throw new ApiTokenAbilityMissing;
        }
        foreach ($abilities as $required) {
            if (! $current->can($required)) {
                throw new ApiTokenAbilityMissing;
            }
        }
        app(CommercialAccessPolicy::class)->assertFeature($currentCompany, 'api.enabled');
        if (in_array(ApiTokenAbility::WebhooksManage->value, $abilities, true)) {
            app(CommercialAccessPolicy::class)->assertFeature($currentCompany, 'webhooks.enabled');
        }
    }
}
