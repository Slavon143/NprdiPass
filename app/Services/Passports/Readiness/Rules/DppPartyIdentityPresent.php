<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\DppPartyRole;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class DppPartyIdentityPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.party.identity.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Manufacturer;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $configured = $context->config['required_party_role'] ?? 'manufacturer';
        $role = is_string($configured) ? DppPartyRole::tryFrom($configured) : null;
        $prefix = $role === null ? 'manufacturer' : $role->value;
        $section = DppSectionKey::ManufacturerAndOperator;
        $present = fn (string $field): bool => is_string($value = $context->fieldValue($section->value, $field)) && trim($value) !== '';
        $missing = [];
        foreach ([$prefix.'_display_name', $prefix.'_address'] as $field) {
            if (! $present($field)) {
                $missing[] = $field;
            }
        }
        if (! array_any(['email', 'phone', 'website'], fn ($suffix) => $present($prefix.'_'.$suffix))) {
            $missing[] = $prefix.'_email';
        }
        $passed = $role !== null && $missing === [];

        return new ReadinessRuleResult(
            $this->code(), $this->group(), $this->severity(),
            $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            'readiness.dpp.party.identity.title',
            $passed ? 'readiness.dpp.party.identity.passed' : 'readiness.dpp.party.identity.failed',
            section: $section,
            field: $missing[0] ?? $prefix.'_display_name',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                'passport_section', $section->value, 'catalog.products.passport.edit',
                ['product' => $context->product->uuid ?? ''], 'Manufacturer, importer and operator',
            ),
            safeContext: ['party_role' => $role?->value, 'missing_fields' => $missing, 'identity_verified' => false],
        );
    }
}
