<?php

namespace App\Actions\Passports;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\DppRegistryPreparation;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Catalog\ManagedProductGate;
use App\Services\Passports\Qr\PassportQrPayloadFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PrepareDppRegistryRegistration
{
    public function __construct(private readonly CompanyAuthorizer $authorizer, private readonly AuditLogger $auditLogger) {}

    public function handle(User $actor, Company $company, Product $product, string $expectedPublishedVersionUuid, string $environment): DppRegistryPreparation
    {
        if (! in_array($environment, ['acceptance', 'production'], true)) {
            throw ValidationException::withMessages(['environment' => 'Unsupported registry environment.']);
        }

        return DB::transaction(function () use ($actor, $company, $product, $expectedPublishedVersionUuid, $environment): DppRegistryPreparation {
            $company = Company::query()->whereKey($company->getKey())->firstOrFail();
            $this->authorizer->authorize($actor, $company, CompanyPermission::PassportsManage);
            $product = app(ManagedProductGate::class)->forMutation($company, $product);
            $passport = ProductPassport::query()->forCompany($company)->where('product_id', $product->getKey())->lockForUpdate()->firstOrFail();
            $version = ProductPassportVersion::query()->forCompany($company)->where('passport_id', $passport->getKey())
                ->whereKey($passport->current_published_version_id)->lockForUpdate()->first();
            if ($passport->status !== ProductPassportStatus::Published || $version === null
                || $version->status !== ProductPassportVersionStatus::Published || $version->uuid !== $expectedPublishedVersionUuid
                || $version->content_checksum === null) {
                throw new ConflictHttpException('Refresh this page: the selected publication is no longer current.');
            }
            $existing = DppRegistryPreparation::query()->forCompany($company)->where('published_version_id', $version->getKey())->where('environment', $environment)->first();
            if ($existing !== null) {
                return $existing;
            }
            $uri = app(PassportQrPayloadFactory::class)->create($passport->public_id);
            $parts = parse_url($uri);
            $validUri = is_array($parts) && ($parts['scheme'] ?? null) === 'https'
                && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
                && ! isset($parts['query']) && ! isset($parts['fragment'])
                && strlen($uri) <= 2000 && filter_var($uri, FILTER_VALIDATE_URL) !== false;
            $blockers = ['organisation_verification_unconfirmed', 'official_registry_schema_unconfirmed', 'registry_transport_not_implemented', 'upi_conformance_and_resolution_unverified'];
            if (! $validUri) {
                $blockers[] = 'public_https_uri_required';
            }
            $record = new DppRegistryPreparation;
            $record->forceFill([
                'company_id' => $company->getKey(), 'passport_id' => $passport->getKey(),
                'published_version_id' => $version->getKey(), 'environment' => $environment,
                'status' => 'blocked', 'snapshot_checksum' => $version->content_checksum,
                'passport_uri' => $validUri ? $uri : null, 'registration_uri' => null, 'blockers' => $blockers,
            ])->save();
            $this->auditLogger->logTenant($company, AuditEvent::PassportRegistryPrepared, $actor, $record, [
                'passport_uuid' => $passport->uuid, 'published_version_uuid' => $version->uuid,
                'snapshot_checksum' => $version->content_checksum, 'environment' => $environment,
                'status' => 'blocked', 'blockers' => $blockers,
            ]);

            return $record;
        });
    }
}
