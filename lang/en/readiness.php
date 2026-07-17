<?php

return [
    'label' => 'Readiness',

    // ──────────────────────────────────────────────
    // BLOCKERS
    // ──────────────────────────────────────────────

    'catalog.product.exists' => [
        'title' => 'Product exists',
        'message' => 'The product exists in the catalog.',
        'passed' => 'The product exists in the catalog.',
        'failed' => 'The product does not exist in the catalog.',
    ],
    'catalog.product.active' => [
        'title' => 'Product is active',
        'message' => 'The product is active and not archived.',
        'passed' => 'The product is active and not archived.',
        'failed' => 'The product is archived and cannot be published.',
    ],
    'catalog.product.name.present' => [
        'title' => 'Product name is set',
        'message' => 'The product has a name.',
        'passed' => 'The product has a name.',
        'failed' => 'The product is missing a name.',
    ],
    'catalog.product.identifier.present' => [
        'title' => 'Product identifier present',
        'message' => 'At least one identifier (SKU, GTIN, or MPN) is set.',
        'passed' => 'At least one identifier (SKU, GTIN, or MPN) is set.',
        'failed' => 'No product identifier is set. Provide at least a SKU, GTIN, or MPN.',
    ],
    'passport.exists' => [
        'title' => 'Passport exists',
        'message' => 'A product passport has been created for this product.',
        'passed' => 'A product passport has been created for this product.',
        'failed' => 'No product passport has been created for this product.',
    ],
    'passport.status.editable' => [
        'title' => 'Passport is editable',
        'message' => 'The passport is in a state that can be published.',
        'passed' => 'The passport is in an editable state.',
        'failed' => 'The passport is not in a state that can be published.',
    ],
    'passport.current_draft.exists' => [
        'title' => 'Draft version exists',
        'message' => 'The passport has a current draft version.',
        'passed' => 'The passport has a current draft version.',
        'failed' => 'The passport does not have a current draft version.',
    ],
    'passport.current_draft.belongs_to_passport' => [
        'title' => 'Draft belongs to passport',
        'message' => 'The current draft version belongs to this passport.',
        'passed' => 'The current draft version belongs to this passport.',
        'failed' => 'The current draft version does not belong to this passport.',
    ],
    'passport.current_draft.status' => [
        'title' => 'Draft status is valid',
        'message' => 'The current draft has the correct status.',
        'passed' => 'The current draft has the correct status.',
        'failed' => 'The current draft does not have a valid status.',
    ],
    'passport.schema.supported' => [
        'title' => 'Schema version supported',
        'message' => 'The DPP schema version is supported.',
        'passed' => 'The DPP schema version is supported.',
        'failed' => 'The DPP schema version is not supported.',
    ],
    'passport.payload.valid' => [
        'title' => 'Payload is valid',
        'message' => 'The DPP payload passes validation.',
        'passed' => 'The DPP payload is valid.',
        'failed' => 'The DPP payload fails validation.',
    ],
    'passport.payload.size' => [
        'title' => 'Payload size within limit',
        'message' => 'The payload is within the 1 MiB size limit.',
        'passed' => 'The payload is within the 1 MiB size limit.',
        'failed' => 'The payload exceeds the 1 MiB size limit.',
    ],
    'passport.default_language.enabled' => [
        'title' => 'Default language enabled',
        'message' => 'The passport default language has authoring content.',
        'passed' => 'The passport default language has authoring content.',
        'failed' => 'The passport default language has no authoring content.',
    ],
    'passport.core_sections.enabled' => [
        'title' => 'Core sections enabled',
        'message' => 'All required core sections are enabled.',
        'passed' => 'All required core sections are enabled.',
        'failed' => 'Not all required core sections are enabled.',
    ],
    'passport.revision.valid' => [
        'title' => 'Draft revision valid',
        'message' => 'The draft revision is valid (>= 1).',
        'passed' => 'The draft revision is valid.',
        'failed' => 'The draft revision is not valid.',
    ],
    'dpp.identity.name.present' => [
        'title' => 'Public name present',
        'message' => 'The product has a public name for the passport.',
        'passed' => 'The product has a public name for the passport.',
        'failed' => 'No public name is set for the passport.',
    ],
    'dpp.identity.description.present' => [
        'title' => 'Public description present',
        'message' => 'The product has a public description.',
        'passed' => 'The product has a public description.',
        'failed' => 'No public description is set.',
    ],
    'dpp.manufacturer.name.present' => [
        'title' => 'Manufacturer name present',
        'message' => 'A manufacturer name is provided.',
        'passed' => 'A manufacturer name is provided.',
        'failed' => 'No manufacturer name is provided.',
    ],
    'dpp.manufacturer.contact.present' => [
        'title' => 'Manufacturer contact present',
        'message' => 'At least one manufacturer contact channel is provided.',
        'passed' => 'At least one manufacturer contact channel is provided.',
        'failed' => 'No manufacturer contact channel is provided.',
    ],
    'dpp.safety.reviewed' => [
        'title' => 'Safety section reviewed',
        'message' => 'The safety section contains information.',
        'passed' => 'The safety section contains information.',
        'failed' => 'The safety section has not been reviewed or is empty.',
    ],
    'dpp.recycling.instructions.present' => [
        'title' => 'Recycling instructions present',
        'message' => 'Recycling or disposal instructions are provided.',
        'passed' => 'Recycling or disposal instructions are provided.',
        'failed' => 'No recycling or disposal instructions are provided.',
    ],
    'dpp.materials.valid' => [
        'title' => 'Materials valid',
        'message' => 'The materials list is valid with correct percentages.',
        'passed' => 'The materials list is valid with correct percentages.',
        'failed' => 'The materials list is invalid or percentages do not add up.',
        'not_applicable' => 'The materials section is not enabled.',
    ],
    'media.primary.present' => [
        'title' => 'Primary media present',
        'message' => 'The product has a primary image.',
        'passed' => 'The product has a primary image.',
        'failed' => 'The product has no primary image.',
    ],
    'media.primary.belongs_to_product' => [
        'title' => 'Primary media belongs to product',
        'message' => 'The primary image belongs to this product.',
        'passed' => 'The primary image belongs to this product.',
        'failed' => 'The primary image does not belong to this product.',
    ],
    'media.primary.available' => [
        'title' => 'Primary media file available',
        'message' => 'The primary image file exists on storage.',
        'passed' => 'The primary image file exists on storage.',
        'failed' => 'The primary image file is missing from storage.',
    ],
    'documents.references.valid' => [
        'title' => 'Document references valid',
        'message' => 'All referenced documents exist and belong to this product.',
        'passed' => 'All referenced documents exist and belong to this product.',
        'failed' => 'Some referenced documents are missing or do not belong to this product.',
    ],
    'documents.current_version.valid' => [
        'title' => 'Document versions valid',
        'message' => 'All referenced documents have a current version.',
        'passed' => 'All referenced documents have a current version.',
        'failed' => 'Some referenced documents do not have a current version.',
    ],
    'documents.file.available' => [
        'title' => 'Document files available',
        'message' => 'All document files exist on storage.',
        'passed' => 'All document files exist on storage.',
        'failed' => 'Some document files are missing from storage.',
    ],
    'documents.file.metadata.valid' => [
        'title' => 'Document metadata valid',
        'message' => 'Document files have valid PDF metadata.',
        'passed' => 'Document files have valid PDF metadata.',
        'failed' => 'Some document files have invalid PDF metadata.',
    ],
    'certificates.metadata.complete' => [
        'title' => 'Certificate metadata complete',
        'message' => 'Certificate documents have issuer and issue date.',
        'passed' => 'Certificate documents have complete metadata.',
        'failed' => 'Some certificate documents are missing issuer or issue date.',
    ],
    'certificates.not_expired' => [
        'title' => 'Certificates not expired',
        'message' => 'No referenced certificates are expired.',
        'passed' => 'No referenced certificates are expired.',
        'failed' => 'Some referenced certificates are expired.',
    ],

    // ──────────────────────────────────────────────
    // WARNINGS
    // ──────────────────────────────────────────────

    'catalog.product.brand.present' => [
        'title' => 'Product brand present',
        'message' => 'The product has a brand set.',
        'passed' => 'The product has a brand set.',
        'failed' => 'The product has no brand set.',
    ],
    'catalog.product.manufacturer.present' => [
        'title' => 'Product manufacturer present',
        'message' => 'The product has a manufacturer set.',
        'passed' => 'The product has a manufacturer set.',
        'failed' => 'The product has no manufacturer set.',
    ],
    'catalog.product.category.present' => [
        'title' => 'Product has a category',
        'message' => 'At least one category is assigned.',
        'passed' => 'At least one category is assigned.',
        'failed' => 'No category is assigned to the product.',
    ],
    'catalog.product.default_variant.present' => [
        'title' => 'Default variant present',
        'message' => 'The product has a default variant.',
        'passed' => 'The product has a default variant.',
        'failed' => 'The product has no default variant.',
    ],
    'catalog.product.attributes.present' => [
        'title' => 'Product attributes present',
        'message' => 'The product has attribute values.',
        'passed' => 'The product has attribute values.',
        'failed' => 'The product has no attribute values.',
    ],
    'passport.optional_sections.none' => [
        'title' => 'Optional sections enabled',
        'message' => 'At least one optional DPP section is enabled.',
        'passed' => 'At least one optional DPP section is enabled.',
        'failed' => 'No optional DPP sections are enabled.',
    ],
    'dpp.identity.catalog_name_overridden' => [
        'title' => 'Public name differs from catalog',
        'message' => 'The passport uses a different public name than the catalog product name.',
        'passed' => 'The passport public name differs from the catalog name.',
        'failed' => 'The passport public name is the same as the catalog product name.',
    ],
    'dpp.manufacturer.country.present' => [
        'title' => 'Manufacturer country present',
        'message' => 'The manufacturer country is set.',
        'passed' => 'The manufacturer country is set.',
        'failed' => 'The manufacturer country is not set.',
    ],
    'dpp.responsible_operator.present' => [
        'title' => 'Responsible operator present',
        'message' => 'A responsible operator is provided.',
        'passed' => 'A responsible operator is provided.',
        'failed' => 'No responsible operator is provided.',
    ],
    'dpp.safety.emergency_information.present' => [
        'title' => 'Emergency information present',
        'message' => 'Emergency instructions are provided.',
        'passed' => 'Emergency instructions are provided.',
        'failed' => 'No emergency information is provided.',
    ],
    'dpp.safety.storage_information.present' => [
        'title' => 'Storage information present',
        'message' => 'Storage instructions are provided.',
        'passed' => 'Storage instructions are provided.',
        'failed' => 'No storage instructions are provided.',
    ],
    'dpp.recycling.codes.present' => [
        'title' => 'Recycling codes present',
        'message' => 'Recycling codes are provided.',
        'passed' => 'Recycling codes are provided.',
        'failed' => 'No recycling codes are provided.',
    ],
    'dpp.take_back_program.present' => [
        'title' => 'Take-back program present',
        'message' => 'A take-back program is described.',
        'passed' => 'A take-back program is described.',
        'failed' => 'No take-back program is described.',
    ],
    'dpp.materials.present' => [
        'title' => 'Materials present',
        'message' => 'The materials section contains entries.',
        'passed' => 'The materials section contains entries.',
        'failed' => 'The materials section is empty.',
        'not_applicable' => 'The materials section is not enabled.',
    ],
    'dpp.materials.percentage_complete' => [
        'title' => 'Material percentages complete',
        'message' => 'All materials have percentage values.',
        'passed' => 'All materials have percentage values.',
        'failed' => 'Not all materials have percentage values.',
        'not_applicable' => 'The materials section is not enabled.',
    ],
    'dpp.materials.recycled_content.present' => [
        'title' => 'Recycled content present',
        'message' => 'Recycled content information is provided.',
        'passed' => 'Recycled content information is provided.',
        'failed' => 'No recycled content information is provided.',
        'not_applicable' => 'The materials section is not enabled.',
    ],
    'dpp.environmental.claims.present' => [
        'title' => 'Environmental claims present',
        'message' => 'Environmental claims or notes are provided.',
        'passed' => 'Environmental claims or notes are provided.',
        'failed' => 'No environmental claims or notes are provided.',
        'not_applicable' => 'The environmental section is not enabled.',
    ],
    'dpp.environmental.metrics.present' => [
        'title' => 'Environmental metrics present',
        'message' => 'At least one environmental metric is provided.',
        'passed' => 'At least one environmental metric is provided.',
        'failed' => 'No environmental metric is provided.',
        'not_applicable' => 'The environmental section is not enabled.',
    ],
    'dpp.usage.instructions.present' => [
        'title' => 'Usage instructions present',
        'message' => 'Usage instructions are provided.',
        'passed' => 'Usage instructions are provided.',
        'failed' => 'No usage instructions are provided.',
        'not_applicable' => 'The usage section is not enabled.',
    ],
    'dpp.care.instructions.present' => [
        'title' => 'Care instructions present',
        'message' => 'Care instructions are provided.',
        'passed' => 'Care instructions are provided.',
        'failed' => 'No care instructions are provided.',
        'not_applicable' => 'The care section is not enabled.',
    ],
    'dpp.repair.repairability_declared' => [
        'title' => 'Repairability declared',
        'message' => 'Whether the product is repairable is declared.',
        'passed' => 'Repairability is declared.',
        'failed' => 'Repairability has not been declared.',
        'not_applicable' => 'The repair section is not enabled.',
    ],
    'dpp.repair.instructions.present' => [
        'title' => 'Repair instructions present',
        'message' => 'Repair instructions are provided.',
        'passed' => 'Repair instructions are provided.',
        'failed' => 'No repair instructions are provided.',
        'not_applicable' => 'The repair section is not enabled.',
    ],
    'dpp.spare_parts.information.present' => [
        'title' => 'Spare parts information present',
        'message' => 'Spare parts information is provided.',
        'passed' => 'Spare parts information is provided.',
        'failed' => 'No spare parts information is provided.',
        'not_applicable' => 'The spare parts section is not enabled.',
    ],
    'dpp.support.channel.present' => [
        'title' => 'Support channel present',
        'message' => 'At least one support contact channel is provided.',
        'passed' => 'At least one support contact channel is provided.',
        'failed' => 'No support contact channel is provided.',
        'not_applicable' => 'The support section is not enabled.',
    ],
    'dpp.warranty.information.present' => [
        'title' => 'Warranty information present',
        'message' => 'Warranty information is provided.',
        'passed' => 'Warranty information is provided.',
        'failed' => 'No warranty information is provided.',
        'not_applicable' => 'The support section is not enabled.',
    ],
    'media.gallery.present' => [
        'title' => 'Media gallery present',
        'message' => 'The product has more than one image.',
        'passed' => 'The product has a media gallery with multiple images.',
        'failed' => 'The product only has one image.',
    ],
    'media.variant_coverage' => [
        'title' => 'Variant media coverage',
        'message' => 'All active variants have media.',
        'passed' => 'All active variants have media.',
        'failed' => 'Some active variants do not have media.',
    ],
    'documents.public_candidate.present' => [
        'title' => 'Public document candidate present',
        'message' => 'At least one document is marked as passport public.',
        'passed' => 'At least one document is marked as passport public.',
        'failed' => 'No document is marked as passport public.',
    ],
    'documents.referenced.current_version' => [
        'title' => 'Document versions up to date',
        'message' => 'Referenced documents are at the latest version.',
        'passed' => 'All referenced documents are at the latest version.',
        'failed' => 'Some referenced documents have newer versions available.',
    ],
    'certificates.expiring_soon' => [
        'title' => 'Certificates expiring soon',
        'message' => 'No certificates are expiring within the warning period.',
        'passed' => 'No certificates are expiring within the warning period.',
        'failed' => 'Some certificates are expiring within the warning period.',
    ],
    'certificates.no_expiration' => [
        'title' => 'Certificate expiry not set',
        'message' => 'All certificates have an expiration date.',
        'passed' => 'All certificates have an expiration date.',
        'failed' => 'Some certificates have no expiration date.',
    ],
    'certificates.declaration_present' => [
        'title' => 'Declaration of Conformity present',
        'message' => 'A Declaration of Conformity has been linked.',
        'passed' => 'A Declaration of Conformity has been linked.',
        'failed' => 'No Declaration of Conformity has been linked.',
    ],

    // ──────────────────────────────────────────────
    // RECOMMENDATIONS
    // ──────────────────────────────────────────────

    'dpp.environmental.claims.review' => [
        'title' => 'Review environmental claims',
        'message' => 'Environmental claims should be reviewed for accuracy before publication.',
        'passed' => 'Environmental claims have been reviewed.',
        'failed' => 'Environmental claims should be reviewed for accuracy before publication.',
        'not_applicable' => 'The environmental section is not enabled.',
        'recommendation' => 'Environmental claims should be reviewed for accuracy before publication.',
    ],

];
