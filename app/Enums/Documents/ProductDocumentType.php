<?php

namespace App\Enums\Documents;

enum ProductDocumentType: string
{
    case GeneralDocument = 'general_document';
    case Manual = 'manual';
    case TechnicalSpecification = 'technical_specification';
    case SafetyData = 'safety_data';
    case Instruction = 'instruction';
    case DeclarationOfConformity = 'declaration_of_conformity';
    case Certificate = 'certificate';
    case TestReport = 'test_report';
    case SafetyDataSheet = 'safety_data_sheet';
    case Warranty = 'warranty';
    case WarrantyDocument = 'warranty_document';
    case TechnicalDataSheet = 'technical_data_sheet';
    case RecyclingDocument = 'recycling_document';
    case RecyclingGuide = 'recycling_guide';
    case RepairDocument = 'repair_document';
    case EnvironmentalEvidence = 'environmental_evidence';
    case ComplianceEvidence = 'compliance_evidence';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::GeneralDocument => 'General Document',
            self::Manual => 'Manual',
            self::TechnicalSpecification => 'Technical Specification',
            self::SafetyData => 'Safety Data',
            self::Instruction => 'Instruction',
            self::DeclarationOfConformity => 'Declaration of Conformity',
            self::Certificate => 'Certificate',
            self::TestReport => 'Test Report',
            self::SafetyDataSheet => 'Safety Data Sheet',
            self::Warranty => 'Warranty',
            self::WarrantyDocument => 'Warranty Document',
            self::TechnicalDataSheet => 'Technical Data Sheet',
            self::RecyclingDocument => 'Recycling Document',
            self::RecyclingGuide => 'Recycling Guide',
            self::RepairDocument => 'Repair Document',
            self::EnvironmentalEvidence => 'Environmental Evidence',
            self::ComplianceEvidence => 'Compliance Evidence',
            self::Other => 'Other',
        };
    }

    public function requiresReview(): bool
    {
        return match ($this) {
            self::Other, self::GeneralDocument, self::Manual, self::Warranty, self::WarrantyDocument => false,
            default => true,
        };
    }

    public function supportsExpiry(): bool
    {
        return in_array($this, [
            self::Certificate,
            self::DeclarationOfConformity,
            self::TestReport,
            self::EnvironmentalEvidence,
            self::ComplianceEvidence,
        ], true);
    }
}
