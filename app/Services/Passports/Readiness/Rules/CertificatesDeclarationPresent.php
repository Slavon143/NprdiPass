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

class CertificatesDeclarationPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'certificates.declaration_present';
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
        $hasDeclaration = false;

        foreach ($context->referencedDocuments as $document) {
            if (
                $document->currentVersion !== null
                && $document->currentVersion->document_type === ProductDocumentType::DeclarationOfConformity
            ) {
                $hasDeclaration = true;
                break;
            }
        }

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $hasDeclaration ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.certificates.declaration_present.title',
            messageKey: $hasDeclaration ? 'readiness.certificates.declaration_present.passed' : 'readiness.certificates.declaration_present.failed',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'product_document',
                section: null,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Manage Documents',
            ),
            safeContext: [
                'has_declaration_of_conformity' => $hasDeclaration,
            ],
        );
    }
}
