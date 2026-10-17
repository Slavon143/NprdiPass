<?php

use App\Data\Passports\Readiness\PassportReadinessResult;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Data\Passports\Readiness\ReadinessScoreBreakdown;
use App\Data\Passports\Readiness\ReadinessSummary;
use App\Enums\Passports\Readiness\PassportReadinessStatus;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\DppProfile;
use App\Models\DppRequirement;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Services\Dpp\DppRequirementEvaluator;
use App\Services\Passports\Readiness\PassportAssuranceSummary;
use App\Services\Passports\Readiness\PassportReadinessRuleRegistry;
use App\Services\Passports\Readiness\Rules\DppManufacturerContactPresent;
use App\Services\Passports\Readiness\Rules\DppPartyIdentityPresent;
use App\Services\Passports\Readiness\Rules\DppSafetyReviewed;
use App\Support\Passports\ReadinessRulePresenter;
use Carbon\CarbonImmutable;

function requiredEvidenceContext(array $payload = [], array $documents = [], array $existence = []): ReadinessEvaluationContext
{
    return new ReadinessEvaluationContext(
        (new Company)->forceFill(['id' => 11]),
        (new Product)->forceFill(['id' => 22, 'company_id' => 11, 'uuid' => 'product-fixture']),
        (new ProductPassport)->forceFill(['id' => 33, 'uuid' => 'passport-fixture', 'default_language' => 'en']),
        (new ProductPassportVersion)->forceFill(['id' => 44, 'draft_revision' => 7]),
        $payload, $documents, $existence, CarbonImmutable::parse('2026-10-14 12:00:00'),
    );
}

function evidenceRequirement(string $code, array $config = []): DppRequirement
{
    $requirement = (new DppRequirement)->forceFill([
        'key' => 'fixture.'.$code, 'rule_code' => $code, 'level' => 'required', 'evaluation_config' => $config,
    ]);
    $requirement->setRelation('profile', (new DppProfile)->forceFill(['key' => 'fixture-sector', 'version' => 1]));

    return $requirement;
}

function evaluateEvidenceRequirement(DppRequirement $requirement, ReadinessEvaluationContext $context): ReadinessRuleResult
{
    $registered = [];
    foreach (app(PassportReadinessRuleRegistry::class)->all() as $rule) {
        $registered[$rule->code()] = $rule;
    }

    return app(DppRequirementEvaluator::class)->evaluate($requirement, $context, $registered);
}

function approvedSafetyEvidenceContext(): ReadinessEvaluationContext
{
    $version = (new ProductDocumentVersion)->forceFill([
        'id' => 66, 'uuid' => 'version-fixture', 'company_id' => 11, 'document_id' => 55,
        'version_number' => 1, 'document_type' => 'safety_data', 'evidence_type' => 'safety_review',
        'review_status' => 'approved', 'approval_status' => 'approved', 'file_available' => true,
        'reviewed_at' => '2026-10-13 12:00:00', 'reviewed_by_user_id' => 77,
        'approved_at' => '2026-10-13 12:00:00', 'approved_by_user_id' => 77,
        'metadata' => ['passport_uuid' => 'passport-fixture', 'draft_revision' => 7, 'reviewed_sections' => ['safety']],
    ]);
    $document = (new ProductDocument)->forceFill(['id' => 55, 'uuid' => 'document-fixture',
        'company_id' => 11, 'product_id' => 22, 'status' => 'active']);
    $document->setRelation('currentVersion', $version);

    return requiredEvidenceContext([
        'data' => ['safety' => ['warnings' => ['Disconnect power before servicing.']]],
        'document_references' => [['document_uuid' => $document->uuid, 'document_version_uuid' => $version->uuid]],
    ], [$document], [$document->uuid => true]);
}

test('operator details cannot substitute for manufacturer contact', function (array $data, string $status) {
    $result = (new DppManufacturerContactPresent)->evaluate(requiredEvidenceContext(['data' => ['manufacturer_and_operator' => $data]]));
    expect($result->status->value)->toBe($status);
})->with([
    'operator only' => [['responsible_operator_email' => 'operator@example.test'], 'failed'],
    'whitespace' => [['manufacturer_email' => '  '], 'failed'],
    'manufacturer phone' => [['manufacturer_phone' => '+46 123456'], 'passed'],
    'manufacturer email' => [['manufacturer_email' => 'manufacturer@example.test'], 'passed'],
]);

test('pilot safety checks describe content rather than a review and ignore empty list entries', function () {
    $empty = (new DppSafetyReviewed)->evaluate(requiredEvidenceContext(['data' => ['safety' => ['warnings' => ['', '  ']]]]));
    $content = (new DppSafetyReviewed)->evaluate(requiredEvidenceContext(['data' => ['safety' => ['warnings' => ['Use gloves']]]]));
    expect($empty->status)->toBe(ReadinessRuleStatus::Failed)
        ->and($content->status)->toBe(ReadinessRuleStatus::Passed)
        ->and(app(ReadinessRulePresenter::class)->title($content))->toBe('Safety information present');
    $review = evaluateEvidenceRequirement(evidenceRequirement('dpp.safety.reviewed'), requiredEvidenceContext(['data' => ['safety' => ['warnings' => ['Use gloves']]]]));
    expect($review->status)->toBe(ReadinessRuleStatus::Failed)
        ->and(app(ReadinessRulePresenter::class)->title($review))->toBe('Safety review evidence');
});

test('approved safety evidence is bound to a product passport revision and recorded decision', function () {
    $result = evaluateEvidenceRequirement(evidenceRequirement('dpp.safety.reviewed'), approvedSafetyEvidenceContext());
    expect($result->status)->toBe(ReadinessRuleStatus::Passed)
        ->and($result->messageKey)->toBe('readiness.dpp.requirement.safety_review_passed')
        ->and($result->safeContext['matched_evidence']['version_uuid'])->toBe('version-fixture');
});

test('seeded pilot content checks remain operational and do not claim a safety review', function () {
    $requirement = evidenceRequirement('dpp.safety.reviewed');
    $requirement->getRelation('profile')->forceFill(['key' => 'nordipass-pilot']);
    $result = evaluateEvidenceRequirement($requirement, requiredEvidenceContext(['data' => ['safety' => ['warnings' => ['Use gloves']]]]));
    expect($result->status)->toBe(ReadinessRuleStatus::Passed)
        ->and(app(ReadinessRulePresenter::class)->title($result))->toBe('Safety information present');
    $requirement->forceFill(['evaluation_config' => ['evidence' => ['evidence_type' => 'safety_review']]]);
    expect(evaluateEvidenceRequirement($requirement, requiredEvidenceContext())->status)->toBe(ReadinessRuleStatus::Failed);
});

test('required safety evidence rejects unusable or unrelated versions', function (string $fault) {
    $context = approvedSafetyEvidenceContext();
    $document = $context->referencedDocuments[0];
    $version = $document->currentVersion;
    match ($fault) {
        'pending' => $version->forceFill(['review_status' => 'pending_review', 'approval_status' => 'pending']),
        'no reviewer' => $version->forceFill(['reviewed_by_user_id' => null]),
        'no decision date' => $version->forceFill(['approved_at' => null]),
        'future decision' => $version->forceFill(['reviewed_at' => '2026-10-15']),
        'old revision' => $version->forceFill(['metadata' => array_replace($version->metadata, ['draft_revision' => 6])]),
        'wrong passport' => $version->forceFill(['metadata' => array_replace($version->metadata, ['passport_uuid' => 'other-passport'])]),
        'wrong section' => $version->forceFill(['metadata' => array_replace($version->metadata, ['reviewed_sections' => ['identity']])]),
        'foreign company' => $document->forceFill(['company_id' => 99]),
        'foreign version' => $version->forceFill(['company_id' => 99]),
        'foreign product' => $document->forceFill(['product_id' => 99]),
        'wrong document' => $version->forceFill(['document_id' => 99]),
        'archived' => $document->forceFill(['status' => 'archived']),
        'expired' => $version->forceFill(['valid_until' => '2026-10-13']),
        'not yet valid' => $version->forceFill(['valid_from' => '2026-10-15']),
        'wrong type' => $version->forceFill(['document_type' => 'manual']),
        'no review scope' => $version->forceFill(['evidence_type' => null]),
        'missing file' => $version->forceFill(['file_available' => false]),
        'storage missing' => $context = new ReadinessEvaluationContext(...array_replace(get_object_vars($context), ['storageExistenceResults' => []])),
        'unreferenced' => $context = new ReadinessEvaluationContext(...array_replace(get_object_vars($context), ['normalizedPayload' => ['data' => $context->normalizedPayload['data']]])),
        'wrong pinned version' => $context = new ReadinessEvaluationContext(...array_replace(get_object_vars($context), ['normalizedPayload' => array_replace($context->normalizedPayload,
            ['document_references' => [['document_uuid' => $document->uuid, 'document_version_uuid' => 'other-version']]])])),
    };
    $result = evaluateEvidenceRequirement(evidenceRequirement('dpp.safety.reviewed'), $context);
    expect($result->status)->toBe(ReadinessRuleStatus::Failed)->and($result->safeContext['required_evidence_satisfied'])->toBeFalse();
})->with(['pending', 'no reviewer', 'no decision date', 'future decision', 'old revision', 'wrong passport', 'wrong section',
    'foreign company', 'foreign version', 'foreign product', 'wrong document', 'archived', 'expired', 'not yet valid', 'wrong type',
    'no review scope', 'missing file', 'storage missing', 'unreferenced', 'wrong pinned version']);

test('required profile checks cannot pass an empty evidence collection', function (string $code) {
    $result = evaluateEvidenceRequirement(evidenceRequirement($code), requiredEvidenceContext());
    expect($result->status)->toBe(ReadinessRuleStatus::Failed)
        ->and($result->safeContext['required_evidence_satisfied'])->toBeFalse();
})->with(['documents.references.valid', 'documents.current_version.valid', 'documents.file.available',
    'certificates.not_expired', 'certificates.metadata.complete', 'certificates.declaration_present',
    'dpp.materials.valid', 'dpp.materials.percentage_complete']);

test('required materials still need rows when the section is enabled', function () {
    $result = evaluateEvidenceRequirement(evidenceRequirement('dpp.materials.valid'), requiredEvidenceContext([
        'enabled_sections' => ['materials_and_composition'], 'data' => ['materials_and_composition' => ['materials' => []]],
    ]));
    expect($result->status)->toBe(ReadinessRuleStatus::Failed);
});

test('evidence policy validates all configured keys and types', function (array $policy) {
    $result = evaluateEvidenceRequirement(evidenceRequirement('documents.references.valid', ['evidence' => $policy]), requiredEvidenceContext());
    expect($result->status)->toBe(ReadinessRuleStatus::Failed)->and($result->safeContext['evaluation_error'])->toBe('unsupported_configuration');
})->with([
    [[]], [['unknown' => true]], [['document_types' => ['invented']]], [['document_types' => []]],
    [['bind_to_draft' => 'false']], [['evidence_type' => null]], [['party_role' => 'invented']],
]);

test('explicit document evidence policy accepts a matching reviewed product document', function () {
    $context = approvedSafetyEvidenceContext();
    $context->referencedDocuments[0]->currentVersion->forceFill(['metadata' => ['party_role' => 'manufacturer']]);
    $requirement = evidenceRequirement('documents.references.valid', ['evidence' => [
        'document_types' => ['safety_data'], 'party_role' => 'manufacturer',
    ]]);
    expect(evaluateEvidenceRequirement($requirement, $context)->status)->toBe(ReadinessRuleStatus::Passed);
    $requirement->forceFill(['evaluation_config' => ['evidence' => ['document_types' => ['certificate']]]]);
    expect(evaluateEvidenceRequirement($requirement, $context)->status)->toBe(ReadinessRuleStatus::Failed);
});

test('party identity rules read only the selected role including translated postal details', function (string $role) {
    $base = requiredEvidenceContext([
        'data' => ['manufacturer_and_operator' => [$role.'_email' => 'party@example.test']],
        'translations' => ['en' => ['manufacturer_and_operator' => [
            $role.'_display_name' => 'Named party', $role.'_address' => 'Postal address',
        ]]],
    ]);
    $context = new ReadinessEvaluationContext(...array_replace(get_object_vars($base), ['config' => ['required_party_role' => $role]]));
    $rule = new DppPartyIdentityPresent;
    expect($rule->evaluate($context)->status)->toBe(ReadinessRuleStatus::Passed);
    $other = new ReadinessEvaluationContext(...array_replace(get_object_vars($base), ['config' => ['required_party_role' => $role === 'manufacturer' ? 'importer' : 'manufacturer']]));
    expect($rule->evaluate($other)->status)->toBe(ReadinessRuleStatus::Failed);
})->with(['manufacturer', 'importer', 'responsible_operator']);

test('an evidence policy cannot replace required certificate evidence with another document type', function () {
    $context = approvedSafetyEvidenceContext();
    $result = evaluateEvidenceRequirement(evidenceRequirement('certificates.not_expired', ['evidence' => ['document_types' => ['safety_data']]]), $context);
    expect($result->status)->toBe(ReadinessRuleStatus::Failed);
});

test('safety review policy retains stricter document type selection', function () {
    $requirement = evidenceRequirement('dpp.safety.reviewed', ['evidence' => ['document_types' => ['test_report']]]);
    $context = approvedSafetyEvidenceContext();
    expect(evaluateEvidenceRequirement($requirement, $context)->status)->toBe(ReadinessRuleStatus::Failed);
    $context->referencedDocuments[0]->currentVersion->forceFill(['document_type' => 'test_report', 'issue_date' => '2026-10-13']);
    expect(evaluateEvidenceRequirement($requirement, $context)->status)->toBe(ReadinessRuleStatus::Passed);
});

test('publication score never turns incomplete sector assessment or registry preparation into success', function () {
    $weights = ['blocker' => 10, 'warning' => 3, 'recommendation' => 1];
    $result = new PassportReadinessResult('nordipass-pilot', 2, 1, 'passport-fixture', 'draft-fixture', 7,
        2, 'weighted_ratio', 1, str_repeat('a', 64), PassportReadinessStatus::ReadyWithWarnings, 87,
        new ReadinessScoreBreakdown(87, 87, 100, 0, $weights, ['blocker' => 0, 'warning' => 13, 'recommendation' => 0]),
        new ReadinessSummary(20, 0, 4, 0, 0), [], CarbonImmutable::now(),
        ['regulatory_scope' => ['status' => 'sector_assessment_required']]);
    $summary = app(PassportAssuranceSummary::class)->forResult($result);
    expect($summary['publication']['status'])->toBe('ready_with_warnings')
        ->and($summary['requirements']['status'])->toBe('assessment_required')
        ->and($summary['registry']['status'])->toBe('connection_pending');
    foreach (['en', 'sv'] as $locale) {
        app()->setLocale($locale);
        $html = view('passports.partials.assurance-statuses', ['readiness' => $result])->render();
        expect($html)->toContain('data-testid="assurance-publication"', 'data-testid="assurance-requirements"', 'data-testid="assurance-registry"')
            ->not->toContain('assurance.publication.', 'assurance.requirements.', 'assurance.registry.');
    }
});
