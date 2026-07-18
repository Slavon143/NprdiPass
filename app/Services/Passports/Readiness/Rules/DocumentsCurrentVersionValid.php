<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class DocumentsCurrentVersionValid implements PassportReadinessRule
{
    public function code(): string
    {
        return 'documents.current_version.valid';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Documents;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        if (count($context->referencedDocuments) === 0) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Passed,
                titleKey: 'readiness.documents.current_version.valid.title',
                messageKey: 'readiness.documents.current_version.valid.passed',
                safeContext: ['referenced_documents_count' => 0],
            );
        }

        $missingVersionUuids = [];

        foreach ($context->referencedDocuments as $document) {
            if ($document->currentVersion === null) {
                $missingVersionUuids[] = $document->uuid;
            }
        }

        $passed = count($missingVersionUuids) === 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.documents.current_version.valid.title',
            messageKey: $passed ? 'readiness.documents.current_version.valid.passed' : 'readiness.documents.current_version.valid.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'product_document',
                section: null,
                routeName: 'catalog.products.documents.index',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Product documents',
            ),
            safeContext: [
                'total_documents' => count($context->referencedDocuments),
                'missing_version_uuids' => $missingVersionUuids,
            ],
        );
    }
}
