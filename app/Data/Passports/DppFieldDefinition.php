<?php

namespace App\Data\Passports;

use App\Enums\Passports\DppFieldType;
use App\Enums\Passports\DppSectionKey;

class DppFieldDefinition
{
    /** @param array<string, mixed> $bounds */
    public function __construct(
        public readonly string $key,
        public readonly DppFieldType $type,
        public readonly bool $translatable,
        public readonly bool $nullable,
        public readonly DppSectionKey $section,
        public readonly ?int $maxLength = null,
        public readonly ?int $maxItems = null,
        public readonly ?float $min = null,
        public readonly ?float $max = null,
        public readonly array $bounds = [],
    ) {}

    public function label(): string
    {
        return match ($this->key) {
            'public_name' => 'Public name',
            'public_description' => 'Public description',
            'manufacturer_display_name' => 'Manufacturer display name',
            'responsible_operator_display_name' => 'Responsible operator display name',
            'contact_notes' => 'Contact notes',
            'manufacturer_email' => 'Manufacturer email',
            'manufacturer_website' => 'Manufacturer website',
            'responsible_operator_email' => 'Responsible operator email',
            'responsible_operator_website' => 'Responsible operator website',
            'manufacturer_country' => 'Manufacturer country',
            'responsible_operator_country' => 'Responsible operator country',
            'country_of_origin' => 'Country of origin',
            'manufacturing_countries' => 'Manufacturing countries',
            'production_date' => 'Production date',
            'traceability_notes' => 'Traceability notes',
            'batch_identification_instructions' => 'Batch identification instructions',
            'materials' => 'Materials',
            'composition_notes' => 'Composition notes',
            'warnings' => 'Warnings',
            'hazards' => 'Hazards',
            'personal_protective_equipment' => 'Personal protective equipment',
            'storage_instructions' => 'Storage instructions',
            'emergency_instructions' => 'Emergency instructions',
            'age_restrictions' => 'Age restrictions',
            'usage_instructions' => 'Usage instructions',
            'care_instructions' => 'Care instructions',
            'maintenance_instructions' => 'Maintenance instructions',
            'storage_recommendations' => 'Storage recommendations',
            'repairable' => 'Repairable',
            'spare_parts_available' => 'Spare parts available',
            'spare_parts_url' => 'Spare parts URL',
            'repair_instructions' => 'Repair instructions',
            'disassembly_instructions' => 'Disassembly instructions',
            'spare_parts_notes' => 'Spare parts notes',
            'service_information' => 'Service information',
            'recycling_instructions' => 'Recycling instructions',
            'disposal_instructions' => 'Disposal instructions',
            'take_back_program' => 'Take-back program',
            'recycling_codes' => 'Recycling codes',
            'carbon_footprint_kg_co2e' => 'Carbon footprint (kg CO₂e)',
            'recycled_content_percentage' => 'Recycled content (%)',
            'expected_lifetime_years' => 'Expected lifetime (years)',
            'energy_consumption_kwh' => 'Energy consumption (kWh)',
            'environmental_claims' => 'Environmental claims',
            'environmental_notes' => 'Environmental notes',
            'certification_notes' => 'Certification notes',
            'compliance_summary' => 'Compliance summary',
            'support_email' => 'Support email',
            'support_phone' => 'Support phone',
            'support_url' => 'Support URL',
            'warranty_url' => 'Warranty URL',
            'warranty_summary' => 'Warranty summary',
            'support_notes' => 'Support notes',
            default => $this->key,
        };
    }
}
