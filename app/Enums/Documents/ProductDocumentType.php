<?php

namespace App\Enums\Documents;

enum ProductDocumentType: string
{
    case Instruction = 'instruction';
    case DeclarationOfConformity = 'declaration_of_conformity';
    case Certificate = 'certificate';
    case SafetyDataSheet = 'safety_data_sheet';
    case Warranty = 'warranty';
    case TechnicalDataSheet = 'technical_data_sheet';
    case RecyclingGuide = 'recycling_guide';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Instruction => 'Instruction',
            self::DeclarationOfConformity => 'Declaration of Conformity',
            self::Certificate => 'Certificate',
            self::SafetyDataSheet => 'Safety Data Sheet',
            self::Warranty => 'Warranty',
            self::TechnicalDataSheet => 'Technical Data Sheet',
            self::RecyclingGuide => 'Recycling Guide',
            self::Other => 'Other',
        };
    }
}
