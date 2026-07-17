<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class DocumentsFileMetadataValid implements PassportReadinessRule
{
    public function code(): string
    {
        return 'documents.file.metadata.valid';
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
                titleKey: 'readiness.documents.file.metadata.valid.title',
                messageKey: 'readiness.documents.file.metadata.valid.passed',
                safeContext: ['referenced_documents_count' => 0],
            );
        }

        $invalidMetaUuids = [];

        foreach ($context->referencedDocuments as $document) {
            $version = $document->currentVersion;

            if ($version === null) {
                $invalidMetaUuids[] = $document->uuid;

                continue;
            }

            $mimeTypeValid = $version->mime_type === 'application/pdf';
            $sizeValid = $version->size_bytes > 0;
            $checksumValid = preg_match('/^[a-f0-9]{64}$/', $version->checksum_sha256);

            if (! $mimeTypeValid || ! $sizeValid || ! $checksumValid) {
                $invalidMetaUuids[] = $document->uuid;
            }
        }

        $passed = count($invalidMetaUuids) === 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.documents.file.metadata.valid.title',
            messageKey: $passed ? 'readiness.documents.file.metadata.valid.passed' : 'readiness.documents.file.metadata.valid.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'product_document',
                section: null,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Manage Documents',
            ),
            safeContext: [
                'total_documents' => count($context->referencedDocuments),
                'invalid_metadata_uuids' => $invalidMetaUuids,
            ],
        );
    }
}
