<?php

use App\Services\Passports\Readiness\Rules\CatalogProductActive;
use App\Services\Passports\Readiness\Rules\CatalogProductAttributesPresent;
use App\Services\Passports\Readiness\Rules\CatalogProductBrandPresent;
use App\Services\Passports\Readiness\Rules\CatalogProductCategoryPresent;
use App\Services\Passports\Readiness\Rules\CatalogProductDefaultVariantPresent;
use App\Services\Passports\Readiness\Rules\CatalogProductExists;
use App\Services\Passports\Readiness\Rules\CatalogProductIdentifierPresent;
use App\Services\Passports\Readiness\Rules\CatalogProductManufacturerPresent;
use App\Services\Passports\Readiness\Rules\CatalogProductNamePresent;
use App\Services\Passports\Readiness\Rules\CertificatesDeclarationPresent;
use App\Services\Passports\Readiness\Rules\CertificatesExpiringSoon;
use App\Services\Passports\Readiness\Rules\CertificatesMetadataComplete;
use App\Services\Passports\Readiness\Rules\CertificatesNoExpiration;
use App\Services\Passports\Readiness\Rules\CertificatesNotExpired;
use App\Services\Passports\Readiness\Rules\DocumentsCurrentVersionValid;
use App\Services\Passports\Readiness\Rules\DocumentsFileAvailable;
use App\Services\Passports\Readiness\Rules\DocumentsFileMetadataValid;
use App\Services\Passports\Readiness\Rules\DocumentsPublicCandidatePresent;
use App\Services\Passports\Readiness\Rules\DocumentsReferencedCurrentVersion;
use App\Services\Passports\Readiness\Rules\DocumentsReferencesValid;
use App\Services\Passports\Readiness\Rules\DppCareInstructionsPresent;
use App\Services\Passports\Readiness\Rules\DppEnvironmentalClaimsPresent;
use App\Services\Passports\Readiness\Rules\DppEnvironmentalClaimsReview;
use App\Services\Passports\Readiness\Rules\DppEnvironmentalMetricsPresent;
use App\Services\Passports\Readiness\Rules\DppIdentityCatalogNameOverridden;
use App\Services\Passports\Readiness\Rules\DppIdentityDescriptionPresent;
use App\Services\Passports\Readiness\Rules\DppIdentityNamePresent;
use App\Services\Passports\Readiness\Rules\DppManufacturerContactPresent;
use App\Services\Passports\Readiness\Rules\DppManufacturerCountryPresent;
use App\Services\Passports\Readiness\Rules\DppManufacturerNamePresent;
use App\Services\Passports\Readiness\Rules\DppMaterialsPercentageComplete;
use App\Services\Passports\Readiness\Rules\DppMaterialsPresent;
use App\Services\Passports\Readiness\Rules\DppMaterialsRecycledContentPresent;
use App\Services\Passports\Readiness\Rules\DppMaterialsValid;
use App\Services\Passports\Readiness\Rules\DppRecyclingCodesPresent;
use App\Services\Passports\Readiness\Rules\DppRecyclingInstructionsPresent;
use App\Services\Passports\Readiness\Rules\DppRepairInstructionsPresent;
use App\Services\Passports\Readiness\Rules\DppRepairRepairabilityDeclared;
use App\Services\Passports\Readiness\Rules\DppResponsibleOperatorPresent;
use App\Services\Passports\Readiness\Rules\DppSafetyEmergencyInfoPresent;
use App\Services\Passports\Readiness\Rules\DppSafetyReviewed;
use App\Services\Passports\Readiness\Rules\DppSafetyStorageInfoPresent;
use App\Services\Passports\Readiness\Rules\DppSparePartsInfoPresent;
use App\Services\Passports\Readiness\Rules\DppSupportChannelPresent;
use App\Services\Passports\Readiness\Rules\DppTakeBackProgramPresent;
use App\Services\Passports\Readiness\Rules\DppUsageInstructionsPresent;
use App\Services\Passports\Readiness\Rules\DppWarrantyInfoPresent;
use App\Services\Passports\Readiness\Rules\MediaGalleryPresent;
use App\Services\Passports\Readiness\Rules\MediaPrimaryAvailable;
use App\Services\Passports\Readiness\Rules\MediaPrimaryBelongsToProduct;
use App\Services\Passports\Readiness\Rules\MediaPrimaryPresent;
use App\Services\Passports\Readiness\Rules\MediaVariantCoverage;
use App\Services\Passports\Readiness\Rules\PassportCoreSectionsEnabled;
use App\Services\Passports\Readiness\Rules\PassportCurrentDraftBelongsToPassport;
use App\Services\Passports\Readiness\Rules\PassportCurrentDraftExists;
use App\Services\Passports\Readiness\Rules\PassportCurrentDraftStatus;
use App\Services\Passports\Readiness\Rules\PassportDefaultLanguageEnabled;
use App\Services\Passports\Readiness\Rules\PassportDefaultLanguageSupported;
use App\Services\Passports\Readiness\Rules\PassportEnabledLanguagesSupported;
use App\Services\Passports\Readiness\Rules\PassportExists;
use App\Services\Passports\Readiness\Rules\PassportOptionalSectionsNone;
use App\Services\Passports\Readiness\Rules\PassportPayloadSize;
use App\Services\Passports\Readiness\Rules\PassportPayloadValid;
use App\Services\Passports\Readiness\Rules\PassportRevisionValid;
use App\Services\Passports\Readiness\Rules\PassportSchemaSupported;
use App\Services\Passports\Readiness\Rules\PassportStatusEditable;

return [
    'profile' => 'nordipass-pilot',
    'profile_version' => 1,
    'rule_set_version' => 1,
    'score_algorithm_version' => 1,

    'score_weights' => [
        'blocker' => 10,
        'warning' => 3,
        'recommendation' => 1,
    ],

    'expiry_warning_days' => (int) env('READINESS_EXPIRY_WARNING_DAYS', 30),

    'required_core_sections' => [
        'identity',
        'manufacturer_and_operator',
        'safety',
        'recycling_and_disposal',
    ],

    'rules' => [
        CatalogProductExists::class,
        CatalogProductActive::class,
        CatalogProductNamePresent::class,
        CatalogProductIdentifierPresent::class,
        CatalogProductBrandPresent::class,
        CatalogProductManufacturerPresent::class,
        CatalogProductCategoryPresent::class,
        CatalogProductDefaultVariantPresent::class,
        CatalogProductAttributesPresent::class,
        PassportExists::class,
        PassportStatusEditable::class,
        PassportCurrentDraftExists::class,
        PassportCurrentDraftBelongsToPassport::class,
        PassportCurrentDraftStatus::class,
        PassportSchemaSupported::class,
        PassportPayloadValid::class,
        PassportPayloadSize::class,
        PassportDefaultLanguageEnabled::class,
        PassportCoreSectionsEnabled::class,
        PassportRevisionValid::class,
        PassportOptionalSectionsNone::class,
        PassportDefaultLanguageSupported::class,
        PassportEnabledLanguagesSupported::class,
        DppIdentityNamePresent::class,
        DppIdentityDescriptionPresent::class,
        DppIdentityCatalogNameOverridden::class,
        DppManufacturerNamePresent::class,
        DppManufacturerContactPresent::class,
        DppManufacturerCountryPresent::class,
        DppResponsibleOperatorPresent::class,
        DppSafetyReviewed::class,
        DppSafetyEmergencyInfoPresent::class,
        DppSafetyStorageInfoPresent::class,
        DppRecyclingInstructionsPresent::class,
        DppRecyclingCodesPresent::class,
        DppTakeBackProgramPresent::class,
        DppMaterialsValid::class,
        DppMaterialsPresent::class,
        DppMaterialsPercentageComplete::class,
        DppMaterialsRecycledContentPresent::class,
        DppEnvironmentalClaimsPresent::class,
        DppEnvironmentalMetricsPresent::class,
        DppEnvironmentalClaimsReview::class,
        DppUsageInstructionsPresent::class,
        DppCareInstructionsPresent::class,
        DppRepairRepairabilityDeclared::class,
        DppRepairInstructionsPresent::class,
        DppSparePartsInfoPresent::class,
        DppSupportChannelPresent::class,
        DppWarrantyInfoPresent::class,
        MediaPrimaryPresent::class,
        MediaPrimaryBelongsToProduct::class,
        MediaPrimaryAvailable::class,
        MediaGalleryPresent::class,
        MediaVariantCoverage::class,
        DocumentsReferencesValid::class,
        DocumentsCurrentVersionValid::class,
        DocumentsFileAvailable::class,
        DocumentsFileMetadataValid::class,
        DocumentsPublicCandidatePresent::class,
        DocumentsReferencedCurrentVersion::class,
        CertificatesMetadataComplete::class,
        CertificatesNotExpired::class,
        CertificatesExpiringSoon::class,
        CertificatesNoExpiration::class,
        CertificatesDeclarationPresent::class,
    ],

    'max_evaluation_query_budget' => 20,

    'enabled_rule_groups' => [
        'catalog',
        'passport',
        'identity',
        'manufacturer',
        'safety',
        'recycling',
        'media',
        'documents',
        'certificates',
        'environmental',
        'support',
        'technical',
    ],
];
