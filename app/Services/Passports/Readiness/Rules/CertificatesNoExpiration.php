<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class CertificatesNoExpiration implements PassportReadinessRule
{
    public function code(): string
    {
        return 'certificates.no_expiration';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Certificates;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $certificateTypes = [
            ProductDocumentType::Certificate,
            ProductDocumentType::DeclarationOfConformity,
        ];

        $certDocuments = array_filter(
            $context->referencedDocuments,
            function ($document) use ($certificateTypes) {
                return $document->currentVersion !== null
                    && in_array($document->currentVersion->document_type, $certificateTypes, true);
            },
        );

        if (count($certDocuments) === 0) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Passed,
                titleKey: 'readiness.certificates.no_expiration.title',
                messageKey: 'readiness.certificates.no_expiration.passed',
                safeContext: ['certificate_documents_count' => 0],
            );
        }

        $noExpirationUuids = [];

        foreach ($certDocuments as $document) {
            $version = $document->currentVersion;
            if ($version->expires_at === null) {
                $noExpirationUuids[] = $document->uuid;
            }
        }

        $passed = count($noExpirationUuids) === 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.certificates.no_expiration.title',
            messageKey: $passed ? 'readiness.certificates.no_expiration.passed' : 'readiness.certificates.no_expiration.failed',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'product_document',
                section: null,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Manage Certificates',
            ),
            safeContext: [
                'total_certificates' => count($certDocuments),
                'no_expiration_uuids' => $noExpirationUuids,
            ],
        );
    }
}
