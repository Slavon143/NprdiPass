<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class DocumentsPublicCandidatePresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'documents.public_candidate.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Documents;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        if (count($context->referencedDocuments) === 0) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Failed,
                titleKey: 'readiness.documents.public_candidate.present.title',
                messageKey: 'readiness.documents.public_candidate.present.failed',
                safeContext: ['referenced_documents_count' => 0],
            );
        }

        $hasPublic = false;

        foreach ($context->referencedDocuments as $document) {
            if (
                $document->isActive()
                && $document->currentVersion !== null
                && $document->currentVersion->visibility === ProductDocumentVisibility::PassportPublic
            ) {
                $hasPublic = true;
                break;
            }
        }

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $hasPublic ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.documents.public_candidate.present.title',
            messageKey: $hasPublic ? 'readiness.documents.public_candidate.present.passed' : 'readiness.documents.public_candidate.present.failed',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'product_document',
                section: null,
                routeName: 'catalog.products.documents.index',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Public product documents',
            ),
            safeContext: [
                'total_documents' => count($context->referencedDocuments),
                'has_public_document' => $hasPublic,
            ],
        );
    }
}
