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

class CertificatesExpiringSoon implements PassportReadinessRule
{
    public function code(): string
    {
        return 'certificates.expiring_soon';
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
                titleKey: 'readiness.certificates.expiring_soon.title',
                messageKey: 'readiness.certificates.expiring_soon.passed',
                safeContext: ['certificate_documents_count' => 0],
            );
        }

        $warningDays = $context->config['expiry_warning_days'] ?? 30;
        $evaluationDate = $context->evaluationDate->startOfDay();
        $deadline = $evaluationDate->addDays((int) $warningDays);

        $expiringSoonUuids = [];

        foreach ($certDocuments as $document) {
            $version = $document->currentVersion;
            if (
                $version->expires_at !== null
                && $version->expires_at->gte($evaluationDate)
                && $version->expires_at->lte($deadline)
            ) {
                $expiringSoonUuids[] = $document->uuid;
            }
        }

        $passed = count($expiringSoonUuids) === 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.certificates.expiring_soon.title',
            messageKey: $passed ? 'readiness.certificates.expiring_soon.passed' : 'readiness.certificates.expiring_soon.failed',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'product_document',
                section: null,
                routeName: 'catalog.products.documents.index',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Certificate documents',
            ),
            safeContext: [
                'total_certificates' => count($certDocuments),
                'expiring_soon_uuids' => $expiringSoonUuids,
                'warning_days' => $warningDays,
            ],
        );
    }
}
