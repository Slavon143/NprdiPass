<?php

namespace App\Actions\Passports;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Data\Passports\PublicationResult;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Enums\Passports\Readiness\PassportReadinessStatus;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Events\Passports\ProductPassportPublished;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\Passports\PublicationIdempotencyRecord;
use App\Models\User;
use App\Services\Passports\CanonicalJsonEncoder;
use App\Services\Passports\PassportSnapshotBuilder;
use App\Services\Passports\PassportVersionNumberAllocator;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublishProductPassport
{
    private const OPERATION = 'publish';

    private const IDEMPOTENCY_CACHE_TTL = 3600;

    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
        private readonly PassportSnapshotBuilder $snapshotBuilder,
        private readonly PassportVersionNumberAllocator $versionNumberAllocator,
        private readonly ReadinessContextBuilder $readinessContextBuilder,
        private readonly PassportReadinessEvaluator $readinessEvaluator,
        private readonly CanonicalJsonEncoder $canonicalJsonEncoder,
    ) {}

    public function handle(
        User $actor,
        Company $company,
        Product $product,
        ProductPassport $passport,
        int $expectedRevision,
        bool $acknowledgeWarnings = false,
        ?string $idempotencyKey = null,
    ): PublicationResult {
        if ($idempotencyKey !== null) {
            $fingerprint = $this->computeFingerprint(
                $company,
                $passport,
                $expectedRevision,
                $acknowledgeWarnings,
            );

            $durableResult = $this->resolveIdempotency(
                $company,
                $passport,
                $idempotencyKey,
                $fingerprint,
                $expectedRevision,
            );

            if ($durableResult !== null) {
                return $durableResult;
            }
        }

        DB::beginTransaction();

        try {
            $freshCompany = $this->authorize($actor, $company);
            $this->assertProductBelongsToCompany($freshCompany, $product);
            $this->assertPassportBelongsToProduct($passport, $product);

            $passport = ProductPassport::query()
                ->whereKey($passport->getKey())
                ->lockForUpdate()
                ->first();

            $draft = $passport->currentDraftVersion;

            if ($draft === null || $draft->status !== ProductPassportVersionStatus::Draft) {
                throw new ConflictHttpException('No draft version available to publish.');
            }

            if ($draft->draft_revision !== $expectedRevision) {
                throw new ConflictHttpException(
                    "Revision conflict: expected revision {$expectedRevision}, current revision {$draft->draft_revision}."
                );
            }

            $readinessContext = $this->readinessContextBuilder->build($freshCompany, $product);
            $readinessResult = $this->readinessEvaluator->evaluate($readinessContext);

            if ($readinessResult->status === PassportReadinessStatus::NotReady) {
                $failedBlockerCodes = array_map(
                    fn ($r) => $r->code,
                    array_filter($readinessResult->rules, fn ($r) => $r->status === ReadinessRuleStatus::Failed
                        && $r->severity === ReadinessSeverity::Blocker
                    ),
                );

                throw ValidationException::withMessages([
                    'readiness' => ['The passport is not ready for publication.'],
                    'blockers' => array_values($failedBlockerCodes),
                ]);
            }

            if ($readinessResult->status === PassportReadinessStatus::ReadyWithWarnings && ! $acknowledgeWarnings) {
                $failedWarningCodes = array_map(
                    fn ($r) => $r->code,
                    array_filter($readinessResult->rules, fn ($r) => $r->status === ReadinessRuleStatus::Failed
                        && $r->severity === ReadinessSeverity::Warning
                    ),
                );

                throw ValidationException::withMessages([
                    'acknowledge_warnings' => ['The passport has warnings. Acknowledge them to proceed.'],
                    'warnings' => array_values($failedWarningCodes),
                ]);
            }

            if ($idempotencyKey !== null) {
                $this->insertIdempotencyRecord(
                    $company,
                    $passport,
                    $idempotencyKey,
                    $fingerprint,
                );
            }

            $oldPayload = $draft->payload;
            $oldSchemaVersion = $draft->schema_version;
            $oldRevision = $draft->draft_revision;

            $snapshot = $this->snapshotBuilder->build($oldPayload);
            $versionNumber = $this->versionNumberAllocator->allocate($passport);
            $checksum = $this->canonicalJsonEncoder->hash($snapshot);

            $draft->setAttribute('status', ProductPassportVersionStatus::Published);
            $draft->setAttribute('version_number', $versionNumber);
            $draft->setAttribute('payload', $snapshot);
            $draft->setAttribute('content_checksum', $checksum);
            $draft->setAttribute('published_at', now());
            $draft->setAttribute('published_by', $actor->getKey());
            $draft->save();

            $publishedVersion = $draft;

            $previousPublished = ProductPassportVersion::query()
                ->where('passport_id', $passport->getKey())
                ->where('status', ProductPassportVersionStatus::Published->value)
                ->whereKeyNot($publishedVersion->getKey())
                ->first();

            if ($previousPublished !== null) {
                $previousPublished->setAttribute('status', ProductPassportVersionStatus::Superseded);
                $previousPublished->setAttribute('superseded_at', now());
                $previousPublished->save();
            }

            $newDraft = new ProductPassportVersion;
            $newDraft->setAttribute('company_id', $freshCompany->getKey());
            $newDraft->setAttribute('passport_id', $passport->getKey());
            $newDraft->setAttribute('status', ProductPassportVersionStatus::Draft);
            $newDraft->setAttribute('draft_revision', $oldRevision + 1);
            $newDraft->setAttribute('schema_version', $oldSchemaVersion);
            $newDraft->setAttribute('payload', $oldPayload);
            $newDraft->setAttribute('created_by', $actor->getKey());
            $newDraft->save();

            $wasPublishedBefore = $passport->isPublished();

            $passport->setAttribute('current_published_version_id', $publishedVersion->getKey());
            $passport->setAttribute('current_draft_version_id', $newDraft->getKey());
            $passport->setAttribute('status', ProductPassportStatus::Published);

            if (! $wasPublishedBefore) {
                $passport->setAttribute('first_published_at', now());
            }

            $passport->setAttribute('last_published_at', now());
            $passport->setAttribute('updated_by', $actor->getKey());
            $passport->save();

            $this->auditLogger->logTenant(
                $freshCompany,
                AuditEvent::PassportPublished,
                $actor,
                $passport,
                [
                    'product_uuid' => $product->getAttribute('uuid'),
                    'passport_uuid' => $passport->getAttribute('uuid'),
                    'published_version_uuid' => $publishedVersion->getAttribute('uuid'),
                    'new_draft_version_uuid' => $newDraft->getAttribute('uuid'),
                    'version_number' => $versionNumber,
                    'draft_revision_before' => $oldRevision,
                    'draft_revision_after' => $oldRevision + 1,
                ],
            );

            if ($idempotencyKey !== null) {
                $this->completeIdempotencyRecord(
                    $company,
                    $idempotencyKey,
                    $publishedVersion,
                );
            }

            DB::commit();

            $passport->loadMissing(['currentDraftVersion', 'currentPublishedVersion']);

            event(new ProductPassportPublished($passport, $publishedVersion, $actor));

            $result = new PublicationResult(
                passport: $passport,
                publishedVersion: $publishedVersion,
                newDraftVersion: $newDraft,
                readinessResult: $readinessResult,
            );

            if ($idempotencyKey !== null) {
                $this->cacheIdempotencyResult(
                    $idempotencyKey,
                    $passport,
                    $publishedVersion,
                    $newDraft,
                    $expectedRevision,
                    $fingerprint,
                );
            }

            return $result;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function computeFingerprint(
        Company $company,
        ProductPassport $passport,
        int $expectedRevision,
        bool $acknowledgeWarnings,
    ): string {
        $payload = json_encode([
            'company_uuid' => $company->uuid,
            'passport_uuid' => $passport->uuid,
            'expected_revision' => $expectedRevision,
            'acknowledge_warnings' => $acknowledgeWarnings,
            'operation' => self::OPERATION,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    private function resolveIdempotency(
        Company $company,
        ProductPassport $passport,
        string $idempotencyKey,
        string $fingerprint,
        int $expectedRevision,
    ): ?PublicationResult {
        $cacheKey = "passport_publish:{$idempotencyKey}";
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            if ($cached['passport_uuid'] !== $passport->uuid) {
                throw new ConflictHttpException('Idempotency key already used for a different passport.');
            }

            if ($cached['fingerprint'] !== $fingerprint) {
                if ($cached['revision'] !== $expectedRevision) {
                    throw new ConflictHttpException('Idempotency key reused with a different revision.');
                }

                throw new ConflictHttpException('Idempotency key already used with different request parameters.');
            }

            $publishedVersion = ProductPassportVersion::query()
                ->where('uuid', $cached['published_version_uuid'])
                ->first();

            if ($publishedVersion === null) {
                Cache::forget($cacheKey);

                return null;
            }

            $passport->loadMissing(['currentDraftVersion', 'currentPublishedVersion']);

            return new PublicationResult(
                passport: $passport,
                publishedVersion: $publishedVersion,
                newDraftVersion: $passport->currentDraftVersion,
                readinessResult: $this->readinessEvaluator->evaluate(
                    $this->readinessContextBuilder->build($company, $passport->product),
                ),
            );
        }

        $existing = PublicationIdempotencyRecord::query()
            ->where('company_id', $company->getKey())
            ->where('operation', self::OPERATION)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing === null) {
            return null;
        }

        if ($existing->request_fingerprint !== $fingerprint) {
            throw new ConflictHttpException(
                'Idempotency key already used with different request parameters.',
            );
        }

        if ($existing->isCompleted()) {
            $publishedVersion = ProductPassportVersion::query()
                ->where('id', $existing->published_version_id)
                ->first();

            if ($publishedVersion === null) {
                return null;
            }

            $passport->loadMissing(['currentDraftVersion', 'currentPublishedVersion']);

            $this->cacheIdempotencyResult(
                $idempotencyKey,
                $passport,
                $publishedVersion,
                $passport->currentDraftVersion,
                $expectedRevision,
                $fingerprint,
            );

            return new PublicationResult(
                passport: $passport,
                publishedVersion: $publishedVersion,
                newDraftVersion: $passport->currentDraftVersion,
                readinessResult: $this->readinessEvaluator->evaluate(
                    $this->readinessContextBuilder->build($company, $passport->product),
                ),
            );
        }

        if ($existing->isProcessing()) {
            throw new ConflictHttpException(
                'A publication request with this idempotency key is already in progress.',
            );
        }

        return null;
    }

    private function insertIdempotencyRecord(
        Company $company,
        ProductPassport $passport,
        string $idempotencyKey,
        string $fingerprint,
    ): void {
        $existing = PublicationIdempotencyRecord::query()
            ->where('company_id', $company->getKey())
            ->where('operation', self::OPERATION)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->request_fingerprint !== $fingerprint) {
                throw new ConflictHttpException(
                    'Idempotency key already used with different request parameters.',
                );
            }

            if ($existing->isCompleted()) {
                throw new ConflictHttpException(
                    'This publication has already been completed.',
                );
            }

            throw new ConflictHttpException(
                'A publication request with this idempotency key is already in progress.',
            );
        }

        $record = new PublicationIdempotencyRecord;
        $record->setAttribute('company_id', $company->getKey());
        $record->setAttribute('product_passport_id', $passport->getKey());
        $record->setAttribute('idempotency_key', $idempotencyKey);
        $record->setAttribute('request_fingerprint', $fingerprint);
        $record->setAttribute('operation', self::OPERATION);
        $record->setAttribute('status', 'processing');
        $record->setAttribute('started_at', now());
        $record->setAttribute('expires_at', now()->addDay());
        $record->save();
    }

    private function completeIdempotencyRecord(
        Company $company,
        string $idempotencyKey,
        ProductPassportVersion $publishedVersion,
    ): void {
        PublicationIdempotencyRecord::query()
            ->where('company_id', $company->getKey())
            ->where('operation', self::OPERATION)
            ->where('idempotency_key', $idempotencyKey)
            ->update([
                'status' => 'completed',
                'published_version_id' => $publishedVersion->getKey(),
                'response_code' => 200,
                'completed_at' => now(),
            ]);
    }

    private function cacheIdempotencyResult(
        string $idempotencyKey,
        ProductPassport $passport,
        ProductPassportVersion $publishedVersion,
        ProductPassportVersion $newDraft,
        int $expectedRevision,
        string $fingerprint,
    ): void {
        Cache::put("passport_publish:{$idempotencyKey}", [
            'published_version_uuid' => $publishedVersion->uuid,
            'new_draft_version_uuid' => $newDraft->uuid,
            'passport_uuid' => $passport->uuid,
            'revision' => $expectedRevision,
            'fingerprint' => $fingerprint,
        ], now()->addSeconds(self::IDEMPOTENCY_CACHE_TTL));
    }

    private function authorize(User $actor, Company $company): Company
    {
        $freshCompany = Company::query()->find($company->getKey());

        if ($freshCompany?->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $freshCompany, CompanyPermission::PassportsManage);

        return $freshCompany;
    }

    private function assertProductBelongsToCompany(Company $company, Product $product): void
    {
        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    private function assertPassportBelongsToProduct(ProductPassport $passport, Product $product): void
    {
        if ((int) $passport->getAttribute('product_id') !== (int) $product->getKey()) {
            throw new NotFoundHttpException;
        }
    }
}
