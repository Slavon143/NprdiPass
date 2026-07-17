<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class DocumentsFileAvailable implements PassportReadinessRule
{
    public function code(): string
    {
        return 'documents.file.available';
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
                titleKey: 'readiness.documents.file.available.title',
                messageKey: 'readiness.documents.file.available.passed',
                safeContext: ['referenced_documents_count' => 0],
            );
        }

        $missingFiles = [];

        foreach ($context->referencedDocuments as $document) {
            $docUuid = $document->uuid;
            if (isset($context->storageExistenceResults[$docUuid]) && $context->storageExistenceResults[$docUuid] !== true) {
                $missingFiles[] = $docUuid;
            }
        }

        $passed = count($missingFiles) === 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.documents.file.available.title',
            messageKey: $passed ? 'readiness.documents.file.available.passed' : 'readiness.documents.file.available.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'product_document',
                section: null,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Manage Documents',
            ),
            safeContext: [
                'total_documents' => count($context->referencedDocuments),
                'missing_file_uuids' => $missingFiles,
            ],
        );
    }
}
