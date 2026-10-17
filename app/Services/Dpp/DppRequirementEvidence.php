<?php

namespace App\Services\Dpp;

use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Enums\Documents\ProductDocumentExpiryState;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\DppRequirementLevel;
use App\Enums\Passports\DppPartyRole;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Models\Catalog\ProductDocument;
use App\Models\DppRequirement;

class DppRequirementEvidence
{
    public function configurationValid(mixed $evidence): bool
    {
        if (! is_array($evidence) || $evidence === []
            || array_diff(array_keys($evidence), ['document_types', 'evidence_type', 'bind_to_draft', 'party_role']) !== []) {
            return false;
        }
        if (array_key_exists('document_types', $evidence) && (! is_array($evidence['document_types'])
            || ! array_is_list($evidence['document_types']) || $evidence['document_types'] === []
            || ! array_all($evidence['document_types'], fn ($type) => is_string($type) && ProductDocumentType::tryFrom($type) !== null))) {
            return false;
        }
        if (array_key_exists('bind_to_draft', $evidence) && ! is_bool($evidence['bind_to_draft'])) {
            return false;
        }
        if (array_key_exists('evidence_type', $evidence) && (! is_string($evidence['evidence_type'])
            || trim($evidence['evidence_type']) === '' || strlen($evidence['evidence_type']) > 120)) {
            return false;
        }

        return ! array_key_exists('party_role', $evidence)
            || (is_string($evidence['party_role']) && DppPartyRole::tryFrom($evidence['party_role']) !== null);
    }

    /** @return array<string, mixed>|null */
    public function policy(DppRequirement $requirement, ReadinessRuleGroup $group): ?array
    {
        $configured = $requirement->evaluation_config['evidence'] ?? null;
        if ($requirement->getRelation('profile')->key === 'nordipass-pilot' && ! is_array($configured)) {
            // Seeded pilot requirements reproduce operational checks; they
            // are not assertions of sector evidence or a recorded review.
            return null;
        }
        if (($requirement->rule_code ?? $requirement->key) === 'dpp.safety.reviewed') {
            // A profile that explicitly asks for a review needs a recorded
            // decision. The pilot's content-presence check is not a review.
            $policy = array_replace(is_array($configured) ? $configured : [], [
                'document_types' => ['safety_data', 'safety_data_sheet', 'test_report', 'compliance_evidence'],
                'evidence_type' => 'safety_review', 'bind_to_draft' => true,
            ]);
            if (isset($configured['document_types'])) {
                $policy['document_types'] = array_values(array_intersect($policy['document_types'], $configured['document_types']));
            }

            return $policy;
        }
        if (! is_array($configured) && $requirement->level !== DppRequirementLevel::Required) {
            return null;
        }

        $baseline = match ($group) {
            ReadinessRuleGroup::Documents => [],
            ReadinessRuleGroup::Certificates => ['document_types' => ($requirement->rule_code ?? $requirement->key) === 'certificates.declaration_present'
                ? ['declaration_of_conformity'] : ['certificate', 'declaration_of_conformity']],
            default => null,
        };
        if (! is_array($configured)) {
            return $baseline;
        }
        $policy = array_replace($baseline ?? [], $configured);
        if (isset($baseline['document_types'], $configured['document_types'])) {
            $policy['document_types'] = array_values(array_intersect($baseline['document_types'], $configured['document_types']));
        }

        return $policy;
    }

    /** @param array<string, mixed> $policy */
    public function matchingDocument(array $policy, ReadinessEvaluationContext $context, ?string $section): ?ProductDocument
    {
        foreach ($context->referencedDocuments as $document) {
            if ($this->qualifies($document, $policy, $context, $section)) {
                return $document;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $policy */
    private function qualifies(ProductDocument $document, array $policy, ReadinessEvaluationContext $context, ?string $section): bool
    {
        $version = $document->currentVersion;
        if ($context->product === null || $version === null || ! $document->isActive()
            || (int) $document->company_id !== (int) $context->company->getKey()
            || (int) $document->product_id !== (int) $context->product->getKey()
            || (int) $version->company_id !== (int) $context->company->getKey()
            || (int) $version->document_id !== (int) $document->getKey()
            || ! $version->isApproved() || ! $version->file_available
            || ($context->storageExistenceResults[$document->uuid] ?? false) !== true) {
            return false;
        }
        $reference = collect($context->normalizedPayload['document_references'] ?? [])->firstWhere('document_uuid', $document->uuid);
        if (! is_array($reference) || (isset($reference['document_version_uuid']) && $reference['document_version_uuid'] !== $version->uuid)) {
            return false;
        }
        if (isset($policy['document_types']) && ! in_array($version->document_type->value, $policy['document_types'], true)) {
            return false;
        }
        if (isset($policy['evidence_type']) && $version->evidence_type !== $policy['evidence_type']) {
            return false;
        }
        if ($version->reviewed_by_user_id === null || $version->approved_by_user_id === null
            || $version->reviewed_at === null || $version->approved_at === null
            || $version->reviewed_at->greaterThan($context->evaluationDate)
            || $version->approved_at->greaterThan($context->evaluationDate)) {
            return false;
        }
        $from = $version->valid_from ?? $version->issue_date;
        $until = $version->valid_until ?? $version->expires_at;
        if (($from !== null && $from->greaterThan($context->evaluationDate->startOfDay()))
            || ($until !== null && $until->lessThan($context->evaluationDate->startOfDay()))
            || ($version->document_type->supportsExpiry() && $version->expiryState($context->evaluationDate) === ProductDocumentExpiryState::Unknown)) {
            return false;
        }
        $metadata = $version->metadata ?? [];
        if (isset($policy['party_role']) && ($metadata['party_role'] ?? null) !== $policy['party_role']) {
            return false;
        }
        if (($policy['bind_to_draft'] ?? false) === true) {
            $section ??= 'safety';
            if ($context->passport === null || $context->currentDraft === null
                || ($metadata['passport_uuid'] ?? null) !== $context->passport->uuid
                || ($metadata['draft_revision'] ?? null) !== $context->currentDraft->draft_revision
                || ! is_array($metadata['reviewed_sections'] ?? null)
                || ! in_array($section, $metadata['reviewed_sections'], true)) {
                return false;
            }
        }

        return true;
    }
}
