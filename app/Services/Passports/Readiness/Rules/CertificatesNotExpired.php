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

class CertificatesNotExpired implements PassportReadinessRule
{
    public function code(): string
    {
        return 'certificates.not_expired';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Certificates;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
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
                titleKey: 'readiness.certificates.not_expired.title',
                messageKey: 'readiness.certificates.not_expired.passed',
                safeContext: ['certificate_documents_count' => 0],
            );
        }

        $expiredUuids = [];

        foreach ($certDocuments as $document) {
            $version = $document->currentVersion;
            if ($version->expires_at !== null && $version->expires_at->isBefore($context->evaluationDate->startOfDay())) {
                $expiredUuids[] = $document->uuid;
            }
        }

        $passed = count($expiredUuids) === 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.certificates.not_expired.title',
            messageKey: $passed ? 'readiness.certificates.not_expired.passed' : 'readiness.certificates.not_expired.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'product_document',
                section: null,
                routeName: 'catalog.products.documents.index',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Certificate documents',
            ),
            safeContext: [
                'total_certificates' => count($certDocuments),
                'expired_uuids' => $expiredUuids,
            ],
        );
    }
}
