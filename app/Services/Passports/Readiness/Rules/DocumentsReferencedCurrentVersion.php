<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class DocumentsReferencedCurrentVersion implements PassportReadinessRule
{
    public function code(): string
    {
        return 'documents.referenced.current_version';
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
                status: ReadinessRuleStatus::Passed,
                titleKey: 'readiness.documents.referenced.current_version.title',
                messageKey: 'readiness.documents.referenced.current_version.passed',
                safeContext: ['referenced_documents_count' => 0],
            );
        }

        $documentRefs = $context->normalizedPayload['document_references'] ?? [];

        if (! is_array($documentRefs) || count($documentRefs) === 0) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Passed,
                titleKey: 'readiness.documents.referenced.current_version.title',
                messageKey: 'readiness.documents.referenced.current_version.passed',
                safeContext: ['document_references_count' => 0],
            );
        }

        $outdatedUuids = [];
        $refUuidToVersionMap = [];

        foreach ($documentRefs as $ref) {
            $refUuid = $ref['document_uuid'] ?? '';
            $refVersion = $ref['version_uuid'] ?? null;
            if ($refUuid !== '') {
                $refUuidToVersionMap[$refUuid] = $refVersion;
            }
        }

        foreach ($context->referencedDocuments as $document) {
            $docUuid = $document->uuid;
            $refVersionUuid = $refUuidToVersionMap[$docUuid] ?? null;

            if ($refVersionUuid !== null && $document->currentVersion !== null) {
                if ($document->currentVersion->uuid !== $refVersionUuid) {
                    $outdatedUuids[] = $docUuid;
                }
            }
        }

        $passed = count($outdatedUuids) === 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.documents.referenced.current_version.title',
            messageKey: $passed ? 'readiness.documents.referenced.current_version.passed' : 'readiness.documents.referenced.current_version.failed',
            safeContext: [
                'total_referenced' => count($context->referencedDocuments),
                'outdated_version_uuids' => $outdatedUuids,
            ],
        );
    }
}
