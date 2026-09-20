<?php

namespace Tests\Unit\Passports\Readiness;

use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Models\Company;
use App\Services\Passports\Readiness\Rules\DppCareInstructionsPresent;
use App\Services\Passports\Readiness\Rules\DppEnvironmentalClaimsReview;
use App\Services\Passports\Readiness\Rules\DppEnvironmentalMetricsPresent;
use App\Services\Passports\Readiness\Rules\DppMaterialsPercentageComplete;
use App\Services\Passports\Readiness\Rules\DppMaterialsPresent;
use App\Services\Passports\Readiness\Rules\DppMaterialsRecycledContentPresent;
use App\Services\Passports\Readiness\Rules\DppRepairInstructionsPresent;
use App\Services\Passports\Readiness\Rules\DppSparePartsInfoPresent;
use App\Services\Passports\Readiness\Rules\DppSupportChannelPresent;
use App\Services\Passports\Readiness\Rules\DppUsageInstructionsPresent;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReadinessEditorFieldAuditTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function context(array $payload): ReadinessEvaluationContext
    {
        return new ReadinessEvaluationContext(
            company: Company::factory()->make(),
            product: null,
            passport: null,
            currentDraft: null,
            normalizedPayload: $payload,
            referencedDocuments: [],
            storageExistenceResults: [],
            evaluationDate: CarbonImmutable::now(),
        );
    }

    public function test_empty_material_list_produces_one_actionable_warning_and_two_deferred_checks(): void
    {
        $context = $this->context([
            'enabled_sections' => ['materials_and_composition'],
            'data' => ['materials_and_composition' => ['materials' => []]],
        ]);

        $present = (new DppMaterialsPresent)->evaluate($context);
        $percentages = (new DppMaterialsPercentageComplete)->evaluate($context);
        $recycled = (new DppMaterialsRecycledContentPresent)->evaluate($context);

        $this->assertSame(ReadinessRuleStatus::Failed, $present->status);
        $this->assertSame('materials', $present->field);
        $this->assertSame(ReadinessRuleStatus::NotApplicable, $percentages->status);
        $this->assertSame(ReadinessRuleStatus::NotApplicable, $recycled->status);
    }

    public function test_material_follow_up_checks_point_to_material_rows(): void
    {
        $context = $this->context([
            'enabled_sections' => ['materials_and_composition'],
            'data' => [
                'materials_and_composition' => [
                    'materials' => [['name' => 'Steel']],
                ],
            ],
        ]);

        $percentages = (new DppMaterialsPercentageComplete)->evaluate($context);
        $recycled = (new DppMaterialsRecycledContentPresent)->evaluate($context);

        $this->assertSame(ReadinessRuleStatus::Failed, $percentages->status);
        $this->assertSame('materials', $percentages->field);
        $this->assertSame(ReadinessRuleStatus::Failed, $recycled->status);
        $this->assertSame('materials', $recycled->field);
    }

    public function test_translated_usage_care_and_repair_fields_are_evaluated(): void
    {
        $context = $this->context([
            'enabled_sections' => ['usage_and_care', 'repair_and_spare_parts'],
            'data' => [
                'repair_and_spare_parts' => [
                    'repairable' => true,
                    'spare_parts_available' => true,
                    'spare_parts' => [['name' => 'Battery']],
                ],
            ],
            'translations' => [
                'sv' => [
                    'usage_and_care' => [
                        'usage_steps' => ['Starta produkten.'],
                        'maintenance_instructions' => 'Kontrollera varje månad.',
                    ],
                    'repair_and_spare_parts' => [
                        'repair_instructions' => 'Koppla bort batteriet först.',
                    ],
                ],
            ],
        ]);

        $this->assertSame(ReadinessRuleStatus::Passed, (new DppUsageInstructionsPresent)->evaluate($context)->status);
        $this->assertSame(ReadinessRuleStatus::Passed, (new DppCareInstructionsPresent)->evaluate($context)->status);
        $this->assertSame(ReadinessRuleStatus::Passed, (new DppRepairInstructionsPresent)->evaluate($context)->status);
        $this->assertSame(ReadinessRuleStatus::Passed, (new DppSparePartsInfoPresent)->evaluate($context)->status);
    }

    public function test_structured_support_channel_satisfies_support_rule(): void
    {
        $context = $this->context([
            'enabled_sections' => ['support_and_contact'],
            'data' => [
                'support_and_contact' => [
                    'support_channels' => [['type' => 'email', 'value' => 'help@example.test']],
                ],
            ],
        ]);

        $result = (new DppSupportChannelPresent)->evaluate($context);

        $this->assertSame(ReadinessRuleStatus::Passed, $result->status);
        $this->assertSame('support_channels', $result->field);
    }

    public function test_structured_and_zero_environmental_metrics_are_valid_information(): void
    {
        $structured = $this->context([
            'enabled_sections' => ['environmental_information'],
            'data' => [
                'environmental_information' => [
                    'environmental_metrics' => [['metric_code' => 'lifetime', 'value' => '10', 'unit' => 'years']],
                ],
            ],
        ]);
        $zero = $this->context([
            'enabled_sections' => ['environmental_information'],
            'data' => ['environmental_information' => ['recycled_content_percentage' => '0']],
        ]);

        $rule = new DppEnvironmentalMetricsPresent;

        $this->assertSame(ReadinessRuleStatus::Passed, $rule->evaluate($structured)->status);
        $this->assertSame(ReadinessRuleStatus::Passed, $rule->evaluate($zero)->status);
    }

    public function test_environmental_claim_review_is_not_applicable_without_claims_and_passes_when_reviewed(): void
    {
        $empty = $this->context(['enabled_sections' => ['environmental_information']]);
        $reviewed = $this->context([
            'enabled_sections' => ['environmental_information'],
            'translations' => [
                'sv' => [
                    'environmental_information' => [
                        'environmental_claim_records' => [[
                            'claim_text' => 'Contains recycled steel.',
                            'review_state' => 'reviewed',
                        ]],
                    ],
                ],
            ],
        ]);

        $rule = new DppEnvironmentalClaimsReview;

        $this->assertSame(ReadinessRuleStatus::NotApplicable, $rule->evaluate($empty)->status);
        $this->assertSame(ReadinessRuleStatus::Passed, $rule->evaluate($reviewed)->status);
    }
}
