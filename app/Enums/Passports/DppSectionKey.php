<?php

namespace App\Enums\Passports;

enum DppSectionKey: string
{
    case Identity = 'identity';
    case ManufacturerAndOperator = 'manufacturer_and_operator';
    case OriginAndTraceability = 'origin_and_traceability';
    case MaterialsAndComposition = 'materials_and_composition';
    case Safety = 'safety';
    case UsageAndCare = 'usage_and_care';
    case RepairAndSpareParts = 'repair_and_spare_parts';
    case RecyclingAndDisposal = 'recycling_and_disposal';
    case EnvironmentalInformation = 'environmental_information';
    case CertificationsAndDocuments = 'certifications_and_documents';
    case SupportAndContact = 'support_and_contact';

    public function label(): string
    {
        return match ($this) {
            self::Identity => 'Identity',
            self::ManufacturerAndOperator => 'Manufacturer & Operator',
            self::OriginAndTraceability => 'Origin & Traceability',
            self::MaterialsAndComposition => 'Materials & Composition',
            self::Safety => 'Safety',
            self::UsageAndCare => 'Usage & Care',
            self::RepairAndSpareParts => 'Repair & Spare Parts',
            self::RecyclingAndDisposal => 'Recycling & Disposal',
            self::EnvironmentalInformation => 'Environmental Information',
            self::CertificationsAndDocuments => 'Certifications & Documents',
            self::SupportAndContact => 'Support & Contact',
        };
    }

    public function isCore(): bool
    {
        return in_array($this, [
            self::Identity,
            self::ManufacturerAndOperator,
            self::Safety,
            self::RecyclingAndDisposal,
        ], true);
    }

    public function isOptional(): bool
    {
        return ! $this->isCore();
    }
}
