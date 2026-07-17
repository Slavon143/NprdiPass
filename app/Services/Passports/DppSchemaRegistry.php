<?php

namespace App\Services\Passports;

use App\Data\Passports\DppFieldDefinition;
use App\Data\Passports\DppSectionDefinition;
use App\Enums\Passports\DppFieldType;
use App\Enums\Passports\DppSectionKey;

class DppSchemaRegistry
{
    /** @var array<string, DppSectionDefinition>|null */
    private ?array $sections = null;

    /** @var array<string, DppFieldDefinition>|null */
    private ?array $flatFields = null;

    /** @return array<string, DppSectionDefinition> */
    public function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $this->sections = [];
        foreach (DppSectionKey::cases() as $key) {
            $this->sections[$key->value] = new DppSectionDefinition(
                key: $key,
                core: $key->isCore(),
                translatable: $this->sectionIsTranslatable($key),
                fields: $this->buildFields($key),
            );
        }

        return $this->sections;
    }

    /** @return string[] */
    public function sectionKeysInOrder(): array
    {
        return array_map(fn (DppSectionKey $k) => $k->value, DppSectionKey::cases());
    }

    /** @return array<string, DppFieldDefinition> */
    public function flatFields(): array
    {
        if ($this->flatFields !== null) {
            return $this->flatFields;
        }

        $this->flatFields = [];
        foreach ($this->sections() as $section) {
            foreach ($section->fields as $field) {
                $this->flatFields[$field->key] = $field;
            }
        }

        return $this->flatFields;
    }

    public function field(string $key): ?DppFieldDefinition
    {
        return $this->flatFields()[$key] ?? null;
    }

    private function sectionIsTranslatable(DppSectionKey $key): bool
    {
        return ! in_array($key, [
            DppSectionKey::OriginAndTraceability,
            DppSectionKey::MaterialsAndComposition,
            DppSectionKey::EnvironmentalInformation,
        ], true);
    }

    /** @return DppFieldDefinition[] */
    private function buildFields(DppSectionKey $key): array
    {
        return match ($key) {
            DppSectionKey::Identity => $this->identityFields(),
            DppSectionKey::ManufacturerAndOperator => $this->manufacturerAndOperatorFields(),
            DppSectionKey::OriginAndTraceability => $this->originAndTraceabilityFields(),
            DppSectionKey::MaterialsAndComposition => $this->materialsAndCompositionFields(),
            DppSectionKey::Safety => $this->safetyFields(),
            DppSectionKey::UsageAndCare => $this->usageAndCareFields(),
            DppSectionKey::RepairAndSpareParts => $this->repairAndSparePartsFields(),
            DppSectionKey::RecyclingAndDisposal => $this->recyclingAndDisposalFields(),
            DppSectionKey::EnvironmentalInformation => $this->environmentalInformationFields(),
            DppSectionKey::CertificationsAndDocuments => $this->certificationsAndDocumentsFields(),
            DppSectionKey::SupportAndContact => $this->supportAndContactFields(),
        };
    }

    /** @return DppFieldDefinition[] */
    private function identityFields(): array
    {
        $s = DppSectionKey::Identity;

        return [
            new DppFieldDefinition('public_name', DppFieldType::ShortText, true, true, $s, maxLength: 500),
            new DppFieldDefinition('public_description', DppFieldType::LongText, true, true, $s, maxLength: 5000),
        ];
    }

    /** @return DppFieldDefinition[] */
    private function manufacturerAndOperatorFields(): array
    {
        $s = DppSectionKey::ManufacturerAndOperator;

        return [
            new DppFieldDefinition('manufacturer_display_name', DppFieldType::ShortText, true, true, $s, maxLength: 500),
            new DppFieldDefinition('responsible_operator_display_name', DppFieldType::ShortText, true, true, $s, maxLength: 500),
            new DppFieldDefinition('contact_notes', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('manufacturer_email', DppFieldType::Email, false, true, $s),
            new DppFieldDefinition('manufacturer_website', DppFieldType::Url, false, true, $s),
            new DppFieldDefinition('responsible_operator_email', DppFieldType::Email, false, true, $s),
            new DppFieldDefinition('responsible_operator_website', DppFieldType::Url, false, true, $s),
            new DppFieldDefinition('manufacturer_country', DppFieldType::CountryCode, false, true, $s),
            new DppFieldDefinition('responsible_operator_country', DppFieldType::CountryCode, false, true, $s),
        ];
    }

    /** @return DppFieldDefinition[] */
    private function originAndTraceabilityFields(): array
    {
        $s = DppSectionKey::OriginAndTraceability;

        return [
            new DppFieldDefinition('country_of_origin', DppFieldType::CountryCode, false, true, $s),
            new DppFieldDefinition('manufacturing_countries', DppFieldType::StringList, false, true, $s, maxItems: 50, maxLength: 3),
            new DppFieldDefinition('production_date', DppFieldType::Date, false, true, $s),
            new DppFieldDefinition('traceability_notes', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('batch_identification_instructions', DppFieldType::LongText, true, true, $s, maxLength: 5000),
        ];
    }

    /** @return DppFieldDefinition[] */
    private function materialsAndCompositionFields(): array
    {
        $s = DppSectionKey::MaterialsAndComposition;

        return [
            new DppFieldDefinition('materials', DppFieldType::MaterialList, false, true, $s, maxItems: 100),
            new DppFieldDefinition('composition_notes', DppFieldType::LongText, true, true, $s, maxLength: 5000),
        ];
    }

    /** @return DppFieldDefinition[] */
    private function safetyFields(): array
    {
        $s = DppSectionKey::Safety;

        return [
            new DppFieldDefinition('warnings', DppFieldType::StringList, true, true, $s, maxItems: 100, maxLength: 1000),
            new DppFieldDefinition('hazards', DppFieldType::StringList, true, true, $s, maxItems: 100, maxLength: 1000),
            new DppFieldDefinition('personal_protective_equipment', DppFieldType::StringList, true, true, $s, maxItems: 100, maxLength: 1000),
            new DppFieldDefinition('storage_instructions', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('emergency_instructions', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('age_restrictions', DppFieldType::ShortText, true, true, $s, maxLength: 500),
        ];
    }

    /** @return DppFieldDefinition[] */
    private function usageAndCareFields(): array
    {
        $s = DppSectionKey::UsageAndCare;

        return [
            new DppFieldDefinition('usage_instructions', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('care_instructions', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('maintenance_instructions', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('storage_recommendations', DppFieldType::LongText, true, true, $s, maxLength: 5000),
        ];
    }

    /** @return DppFieldDefinition[] */
    private function repairAndSparePartsFields(): array
    {
        $s = DppSectionKey::RepairAndSpareParts;

        return [
            new DppFieldDefinition('repairable', DppFieldType::Boolean, false, true, $s),
            new DppFieldDefinition('spare_parts_available', DppFieldType::Boolean, false, true, $s),
            new DppFieldDefinition('spare_parts_url', DppFieldType::Url, false, true, $s),
            new DppFieldDefinition('repair_instructions', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('disassembly_instructions', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('spare_parts_notes', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('service_information', DppFieldType::LongText, true, true, $s, maxLength: 5000),
        ];
    }

    /** @return DppFieldDefinition[] */
    private function recyclingAndDisposalFields(): array
    {
        $s = DppSectionKey::RecyclingAndDisposal;

        return [
            new DppFieldDefinition('recycling_instructions', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('disposal_instructions', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('take_back_program', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('recycling_codes', DppFieldType::StringList, false, true, $s, maxItems: 50, maxLength: 50),
        ];
    }

    /** @return DppFieldDefinition[] */
    private function environmentalInformationFields(): array
    {
        $s = DppSectionKey::EnvironmentalInformation;

        return [
            new DppFieldDefinition('carbon_footprint_kg_co2e', DppFieldType::Decimal, false, true, $s, min: 0),
            new DppFieldDefinition('recycled_content_percentage', DppFieldType::Decimal, false, true, $s, min: 0, max: 100),
            new DppFieldDefinition('expected_lifetime_years', DppFieldType::Decimal, false, true, $s, min: 0),
            new DppFieldDefinition('energy_consumption_kwh', DppFieldType::Decimal, false, true, $s, min: 0),
            new DppFieldDefinition('environmental_claims', DppFieldType::StringList, true, true, $s, maxItems: 50, maxLength: 1000),
            new DppFieldDefinition('environmental_notes', DppFieldType::LongText, true, true, $s, maxLength: 5000),
        ];
    }

    /** @return DppFieldDefinition[] */
    private function certificationsAndDocumentsFields(): array
    {
        $s = DppSectionKey::CertificationsAndDocuments;

        return [
            new DppFieldDefinition('certification_notes', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('compliance_summary', DppFieldType::LongText, true, true, $s, maxLength: 5000),
        ];
    }

    /** @return DppFieldDefinition[] */
    private function supportAndContactFields(): array
    {
        $s = DppSectionKey::SupportAndContact;

        return [
            new DppFieldDefinition('support_email', DppFieldType::Email, false, true, $s),
            new DppFieldDefinition('support_phone', DppFieldType::ShortText, false, true, $s, maxLength: 50),
            new DppFieldDefinition('support_url', DppFieldType::Url, false, true, $s),
            new DppFieldDefinition('warranty_url', DppFieldType::Url, false, true, $s),
            new DppFieldDefinition('warranty_summary', DppFieldType::LongText, true, true, $s, maxLength: 5000),
            new DppFieldDefinition('support_notes', DppFieldType::LongText, true, true, $s, maxLength: 5000),
        ];
    }
}
