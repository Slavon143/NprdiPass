<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class DppSupportChannelPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.support.channel.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Support;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $enabledSections = $context->normalizedPayload['enabled_sections'] ?? [];

        if (! in_array(DppSectionKey::SupportAndContact->value, $enabledSections, true)) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.dpp.support.channel.present.title',
                messageKey: 'readiness.dpp.support.channel.present.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $supportData = $context->normalizedPayload['data']['support_and_contact'] ?? [];

        $supportEmail = $supportData['support_email'] ?? null;
        $supportPhone = $supportData['support_phone'] ?? null;
        $supportUrl = $supportData['support_url'] ?? null;

        $passed = ! empty($supportEmail) || ! empty($supportPhone) || ! empty($supportUrl);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.support.channel.present.title',
            messageKey: $passed ? 'readiness.dpp.support.channel.present.passed' : 'readiness.dpp.support.channel.present.failed',
            section: DppSectionKey::SupportAndContact,
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::SupportAndContact->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Support',
            ),
            safeContext: [
                'has_support_email' => ! empty($supportEmail),
                'has_support_phone' => ! empty($supportPhone),
                'has_support_url' => ! empty($supportUrl),
            ],
        );
    }
}
