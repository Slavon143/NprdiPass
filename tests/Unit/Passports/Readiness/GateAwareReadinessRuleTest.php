<?php

namespace Tests\Unit\Passports\Readiness;

use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Models\Company;
use App\Services\Passports\Readiness\Rules\DppTakeBackProgramPresent;
use App\Services\Passports\Readiness\Rules\DppWarrantyInfoPresent;
use App\Support\Passports\ReadinessRulePresenter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class GateAwareReadinessRuleTest extends TestCase
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

    public function test_take_back_rule_passes_when_no_program_is_declared(): void
    {
        $context = $this->context([
            'data' => [
                'recycling_and_disposal' => [
                    'take_back_program_available' => false,
                ],
            ],
        ]);

        $result = (new DppTakeBackProgramPresent)->evaluate($context);

        $this->assertSame(ReadinessRuleStatus::Passed, $result->status);
    }

    public function test_take_back_rule_fails_when_available_and_not_described(): void
    {
        $context = $this->context([
            'data' => [
                'recycling_and_disposal' => [
                    'take_back_program_available' => true,
                ],
            ],
        ]);

        $result = (new DppTakeBackProgramPresent)->evaluate($context);

        $this->assertSame(ReadinessRuleStatus::Failed, $result->status);
        $this->assertSame('take_back_program', $result->field);
        $this->assertSame(
            'You selected Yes for take-back. Explain who accepts returns, where customers should go, and any conditions.',
            app(ReadinessRulePresenter::class)->message($result),
        );
    }

    public function test_take_back_rule_points_to_declaration_when_yes_or_no_is_not_selected(): void
    {
        $result = (new DppTakeBackProgramPresent)->evaluate($this->context([]));

        $this->assertSame(ReadinessRuleStatus::Failed, $result->status);
        $this->assertSame('take_back_program_available', $result->field);
        $this->assertSame(
            'Confirm whether a take-back program is available by selecting Yes or No.',
            app(ReadinessRulePresenter::class)->message($result),
        );
    }

    public function test_take_back_rule_reads_description_from_default_language_translation(): void
    {
        $result = (new DppTakeBackProgramPresent)->evaluate($this->context([
            'data' => [
                'recycling_and_disposal' => ['take_back_program_available' => true],
            ],
            'translations' => [
                'sv' => [
                    'recycling_and_disposal' => ['take_back_program' => 'Returnera till återförsäljaren.'],
                ],
            ],
        ]));

        $this->assertSame(ReadinessRuleStatus::Passed, $result->status);
    }

    public function test_take_back_rule_passes_when_program_is_described(): void
    {
        $context = $this->context([
            'data' => [
                'recycling_and_disposal' => [
                    'take_back_program' => 'Return to any authorized retailer.',
                ],
            ],
        ]);

        $result = (new DppTakeBackProgramPresent)->evaluate($context);

        $this->assertSame(ReadinessRuleStatus::Passed, $result->status);
    }

    public function test_warranty_rule_passes_when_no_warranty_is_declared(): void
    {
        $context = $this->context([
            'enabled_sections' => ['support_and_contact'],
            'data' => [
                'support_and_contact' => [
                    'warranty_available' => false,
                ],
            ],
        ]);

        $result = (new DppWarrantyInfoPresent)->evaluate($context);

        $this->assertSame(ReadinessRuleStatus::Passed, $result->status);
    }

    public function test_warranty_rule_fails_when_available_and_no_terms(): void
    {
        $context = $this->context([
            'enabled_sections' => ['support_and_contact'],
            'data' => [
                'support_and_contact' => [
                    'warranty_available' => true,
                ],
            ],
        ]);

        $result = (new DppWarrantyInfoPresent)->evaluate($context);

        $this->assertSame(ReadinessRuleStatus::Failed, $result->status);
        $this->assertSame('warranty_summary', $result->field);
    }

    public function test_warranty_rule_points_to_declaration_when_yes_or_no_is_not_selected(): void
    {
        $result = (new DppWarrantyInfoPresent)->evaluate($this->context([
            'enabled_sections' => ['support_and_contact'],
        ]));

        $this->assertSame(ReadinessRuleStatus::Failed, $result->status);
        $this->assertSame('warranty_available', $result->field);
    }

    public function test_warranty_rule_reads_summary_from_default_language_translation(): void
    {
        $result = (new DppWarrantyInfoPresent)->evaluate($this->context([
            'enabled_sections' => ['support_and_contact'],
            'data' => [
                'support_and_contact' => ['warranty_available' => true],
            ],
            'translations' => [
                'sv' => [
                    'support_and_contact' => ['warranty_summary' => 'Två års garanti.'],
                ],
            ],
        ]));

        $this->assertSame(ReadinessRuleStatus::Passed, $result->status);
    }

    public function test_warranty_rule_passes_when_terms_are_present(): void
    {
        $context = $this->context([
            'enabled_sections' => ['support_and_contact'],
            'data' => [
                'support_and_contact' => [
                    'warranty_available' => true,
                    'warranty_summary' => '2-year limited warranty.',
                ],
            ],
        ]);

        $result = (new DppWarrantyInfoPresent)->evaluate($context);

        $this->assertSame(ReadinessRuleStatus::Passed, $result->status);
    }
}
