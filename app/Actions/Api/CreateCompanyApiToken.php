<?php

namespace App\Actions\Api;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\ApiTokenAbility;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use LogicException;

class CreateCompanyApiToken
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<int, ApiTokenAbility|string>  $abilities
     */
    public function execute(
        User $actor,
        Company $company,
        string $name,
        array $abilities,
        ?CarbonInterface $expiresAt,
    ): NewAccessToken {
        $this->validateActorAndCompany($actor, $company);
        $abilityValues = $this->validateAbilities($abilities);
        $this->validateExpiration($expiresAt);

        return DB::transaction(function () use (
            $actor,
            $company,
            $name,
            $abilityValues,
            $expiresAt,
        ): NewAccessToken {
            $newToken = $actor->createToken($name, $abilityValues, $expiresAt);
            $accessToken = $newToken->accessToken;

            if (! $accessToken instanceof PersonalAccessToken) {
                throw new LogicException('Sanctum is not using the NordiPass token model.');
            }

            $accessToken->forceFill(['company_id' => $company->getKey()])->save();

            $this->auditLogger->logTenant(
                $company,
                AuditEvent::ApiTokenCreated,
                $actor,
                $accessToken,
                [
                    'token_name' => $name,
                    'abilities' => $abilityValues,
                    'expires_at' => $expiresAt?->toAtomString(),
                    'created_by' => $actor->uuid,
                ],
            );

            return $newToken;
        });
    }

    private function validateActorAndCompany(User $actor, Company $company): void
    {
        if ($actor->status !== UserStatus::Active || $company->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $membershipExists = CompanyMembership::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $actor->getKey())
            ->exists();

        if (! $membershipExists) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $company, CompanyPermission::ApiTokensCreate);
    }

    /**
     * @param  array<int, ApiTokenAbility|string>  $abilities
     * @return list<string>
     */
    private function validateAbilities(array $abilities): array
    {
        $allowed = array_column(ApiTokenAbility::cases(), 'value');
        $values = array_values(array_unique(array_map(
            fn (ApiTokenAbility|string $ability): string => $ability instanceof ApiTokenAbility
                ? $ability->value
                : $ability,
            $abilities,
        )));

        if ($values === [] || array_diff($values, $allowed) !== []) {
            throw ValidationException::withMessages([
                'abilities' => ['One or more token abilities are not allowed.'],
            ]);
        }

        return $values;
    }

    private function validateExpiration(?CarbonInterface $expiresAt): void
    {
        if ($expiresAt === null && ! config('api.allow_non_expiring_tokens', false)) {
            throw ValidationException::withMessages([
                'expiration' => ['Non-expiring API tokens are disabled.'],
            ]);
        }

        if ($expiresAt === null) {
            return;
        }

        $now = now();
        $max = $now->copy()->addDays((int) config('api.max_token_expiration_days', 365));

        if ($expiresAt->getTimestamp() <= $now->getTimestamp()
            || $expiresAt->getTimestamp() > $max->getTimestamp()) {
            throw ValidationException::withMessages([
                'expiration' => ['The token expiration is outside the allowed range.'],
            ]);
        }
    }
}
