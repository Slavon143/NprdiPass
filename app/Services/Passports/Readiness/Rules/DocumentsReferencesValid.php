<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class DocumentsReferencesValid implements PassportReadinessRule
{
    public function code(): string
    {
        return 'documents.references.valid';
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
        $documentRefs = $context->normalizedPayload['document_references'] ?? [];

        if (! is_array($documentRefs) || count($documentRefs) === 0) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Passed,
                titleKey: 'readiness.documents.references.valid.title',
                messageKey: 'readiness.documents.references.valid.passed',
                safeContext: ['document_references_count' => 0],
            );
        }

        $referencedDocumentUuids = array_column(
            array_values($context->referencedDocuments),
            'uuid',
        );

        $missingUuids = [];

        foreach ($documentRefs as $ref) {
            $refUuid = $ref['document_uuid'] ?? '';
            if ($refUuid !== '' && ! in_array($refUuid, $referencedDocumentUuids, true)) {
                $missingUuids[] = $refUuid;
            }
        }

        $passed = count($missingUuids) === 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.documents.references.valid.title',
            messageKey: $passed ? 'readiness.documents.references.valid.passed' : 'readiness.documents.references.valid.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'product_document',
                section: null,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Manage Documents',
            ),
            safeContext: [
                'total_references' => count($documentRefs),
                'found_documents' => count($context->referencedDocuments),
                'missing_uuids' => $missingUuids,
            ],
        );
    }
}
