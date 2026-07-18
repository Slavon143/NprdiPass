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
        'title' => 'Product can be published',
        'message' => 'The product is active and not archived.',
        'passed' => 'The product is active and not archived.',
        'failed' => 'Only an active, non-archived product can be published.',
    ],
    'catalog.product.name.present' => [
        'title' => 'Product name is set',
        'message' => 'The product has a name.',
        'passed' => 'The product has a name.',
        'failed' => 'The product is missing a name.',
    ],
    'catalog.product.identifier.present' => [
        'title' => 'Default variant identifier',
        'message' => 'At least one identifier (SKU, GTIN, or MPN) is set.',
        'passed' => 'At least one identifier (SKU, GTIN, or MPN) is set.',
        'failed' => 'Add at least one identifier to the default variant: SKU, GTIN, or MPN.',
    ],
    'passport.exists' => [
        'title' => 'Passport exists',
        'message' => 'A product passport has been created for this product.',
        'passed' => 'A product passport has been created for this product.',
        'failed' => 'No product passport has been created for this product.',
    ],
    'passport.status.editable' => [
        'title' => 'Passport draft can be published',
        'message' => 'The passport is in a state that can be published.',
        'passed' => 'The passport is in an editable state.',
        'failed' => 'The passport is not in an editable/publishable state. Create or restore an editable draft.',
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
        'title' => 'Passport fields validate',
        'message' => 'The DPP payload passes validation.',
        'passed' => 'The DPP payload is valid.',
        'failed' => 'Some passport fields fail validation. Open the passport editor and fix the highlighted fields.',
    ],
    'passport.payload.size' => [
        'title' => 'Payload size within limit',
        'message' => 'The payload is within the 1 MiB size limit.',
        'passed' => 'The payload is within the 1 MiB size limit.',
        'failed' => 'The payload exceeds the 1 MiB size limit.',
    ],
    'passport.default_language.enabled' => [
        'title' => 'Default language content',
        'message' => 'The passport default language has authoring content.',
        'passed' => 'The passport default language has authoring content.',
        'failed' => 'Add authoring content for the passport default language.',
    ],
    'passport.core_sections.enabled' => [
        'title' => 'Required passport sections enabled',
        'message' => 'All required core sections are enabled.',
        'passed' => 'All required core sections are enabled.',
        'failed' => 'Enable all required core sections in the passport editor.',
    ],
    'passport.revision.valid' => [
        'title' => 'Draft revision valid',
        'message' => 'The draft revision is valid (>= 1).',
        'passed' => 'The draft revision is valid.',
        'failed' => 'The draft revision is not valid.',
    ],
    'dpp.identity.name.present' => [
        'title' => 'Public product name',
        'message' => 'The product has a public name for the passport.',
        'passed' => 'The product has a public name for the passport.',
        'failed' => 'Add the public product name shown in the passport.',
    ],
    'dpp.identity.description.present' => [
        'title' => 'Public product description',
        'message' => 'The product has a public description.',
        'passed' => 'The product has a public description.',
        'failed' => 'Add the public description shown in the passport.',
    ],
    'dpp.manufacturer.name.present' => [
        'title' => 'Manufacturer name',
        'message' => 'A manufacturer name is provided.',
        'passed' => 'A manufacturer name is provided.',
        'failed' => 'Add the manufacturer name in Manufacturer & Operator.',
    ],
    'dpp.manufacturer.contact.present' => [
        'title' => 'Manufacturer contact',
        'message' => 'At least one manufacturer contact channel is provided.',
        'passed' => 'At least one manufacturer contact channel is provided.',
        'failed' => 'Add at least one manufacturer contact channel, such as email, phone, or website.',
    ],
    'dpp.safety.reviewed' => [
        'title' => 'Safety section reviewed',
        'message' => 'The safety section contains information.',
        'passed' => 'The safety section contains information.',
        'failed' => 'The safety section has not been reviewed or is empty.',
    ],
    'dpp.recycling.instructions.present' => [
        'title' => 'Recycling or disposal instructions',
        'message' => 'Recycling or disposal instructions are provided.',
        'passed' => 'Recycling or disposal instructions are provided.',
        'failed' => 'Add recycling or disposal instructions for the product.',
    ],
    'dpp.materials.valid' => [
        'title' => 'Material composition is valid',
        'message' => 'The materials list is valid with correct percentages.',
        'passed' => 'The materials list is valid with correct percentages.',
        'failed' => 'Fix the material list. Percentages must be valid and add up correctly.',
        'not_applicable' => 'The materials section is not enabled.',
    ],
    'media.primary.present' => [
        'title' => 'Primary product image',
        'message' => 'The product has a primary image.',
        'passed' => 'The product has a primary image.',
        'failed' => 'Upload a product image and mark it as primary.',
    ],
    'media.primary.belongs_to_product' => [
        'title' => 'Primary image belongs to this product',
        'message' => 'The primary image belongs to this product.',
        'passed' => 'The primary image belongs to this product.',
        'failed' => 'Choose a primary image that belongs to this product.',
    ],
    'media.primary.available' => [
        'title' => 'Primary image file exists',
        'message' => 'The primary image file exists on storage.',
        'passed' => 'The primary image file exists on storage.',
        'failed' => 'The selected primary image file is missing. Re-upload it or choose another primary image.',
    ],
    'documents.references.valid' => [
        'title' => 'Referenced documents exist',
        'message' => 'All referenced documents exist and belong to this product.',
        'passed' => 'All referenced documents exist and belong to this product.',
        'failed' => 'Some passport document references are missing or belong to another product. Re-link the correct product documents.',
    ],
    'documents.current_version.valid' => [
        'title' => 'Referenced documents have versions',
        'message' => 'All referenced documents have a current version.',
        'passed' => 'All referenced documents have a current version.',
        'failed' => 'Some referenced documents have no current PDF version. Add a version to each document.',
    ],
    'documents.file.available' => [
        'title' => 'Document PDF files exist',
        'message' => 'All document files exist on storage.',
        'passed' => 'All document files exist on storage.',
        'failed' => 'Some document PDF files are missing from storage. Re-upload the affected document versions.',
    ],
    'documents.file.metadata.valid' => [
        'title' => 'Document PDF metadata',
        'message' => 'Document files have valid PDF metadata.',
        'passed' => 'Document files have valid PDF metadata.',
        'failed' => 'Some PDFs have invalid metadata. Re-upload a valid PDF version.',
    ],
    'certificates.metadata.complete' => [
        'title' => 'Certificate issuer and issue date',
        'message' => 'Certificate documents have issuer and issue date.',
        'passed' => 'Certificate documents have complete metadata.',
        'failed' => 'Some certificate documents are missing issuer or issue date. Edit the document version metadata.',
    ],
    'certificates.not_expired' => [
        'title' => 'Certificate is not expired',
        'message' => 'No referenced certificates are expired.',
        'passed' => 'No referenced certificates are expired.',
        'failed' => 'One or more certificate documents are expired. Upload a newer certificate version or update the expiry date.',
    ],

    // ──────────────────────────────────────────────
    // WARNINGS
    // ──────────────────────────────────────────────

    'catalog.product.brand.present' => [
        'title' => 'Product brand',
        'message' => 'The product has a brand set.',
        'passed' => 'The product has a brand set.',
        'failed' => 'Add a brand if this product is sold under a brand name.',
    ],
    'catalog.product.manufacturer.present' => [
        'title' => 'Catalog manufacturer',
        'message' => 'The product has a manufacturer set.',
        'passed' => 'The product has a manufacturer set.',
        'failed' => 'Add the catalog manufacturer. This can also feed the passport manufacturer fields.',
    ],
    'catalog.product.category.present' => [
        'title' => 'Product has a category',
        'message' => 'At least one category is assigned.',
        'passed' => 'At least one category is assigned.',
        'failed' => 'No category is assigned to the product.',
    ],
    'catalog.product.default_variant.present' => [
        'title' => 'Default variant selected',
        'message' => 'The product has a default variant.',
        'passed' => 'The product has a default variant.',
        'failed' => 'Choose which variant is the default for this product.',
    ],
    'catalog.product.attributes.present' => [
        'title' => 'Product attributes',
        'message' => 'The product has attribute values.',
        'passed' => 'The product has attribute values.',
        'failed' => 'Add the product attribute values used for filtering and passport enrichment.',
    ],
    'passport.optional_sections.none' => [
        'title' => 'Optional sections enabled',
        'message' => 'At least one optional DPP section is enabled.',
        'passed' => 'At least one optional DPP section is enabled.',
        'failed' => 'No optional DPP sections are enabled. Enable optional sections only when this product needs extra public information.',
    ],
    'dpp.identity.catalog_name_overridden' => [
        'title' => 'Public name differs from catalog',
        'message' => 'The passport uses a different public name than the catalog product name.',
        'passed' => 'The passport public name differs from the catalog name.',
        'failed' => 'The passport public name is the same as the catalog product name.',
    ],
    'dpp.manufacturer.country.present' => [
        'title' => 'Manufacturer country',
        'message' => 'The manufacturer country is set.',
        'passed' => 'The manufacturer country is set.',
        'failed' => 'Select the manufacturer country using a two-letter ISO country code, for example SE or DE.',
    ],
    'dpp.responsible_operator.present' => [
        'title' => 'Responsible operator',
        'message' => 'A responsible operator is provided.',
        'passed' => 'A responsible operator is provided.',
        'failed' => 'Add the responsible operator details when they differ from the manufacturer.',
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
        'title' => 'Take-back program',
        'message' => 'A take-back program is described.',
        'passed' => 'A take-back program is described.',
        'failed' => 'Describe how customers can return, recycle, or dispose of the product.',
    ],
    'dpp.materials.present' => [
        'title' => 'Material list',
        'message' => 'The materials section contains entries.',
        'passed' => 'The materials section contains entries.',
        'failed' => 'Add the product materials in Materials & Composition.',
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
        'title' => 'Environmental claims or notes',
        'message' => 'Environmental claims or notes are provided.',
        'passed' => 'Environmental claims or notes are provided.',
        'failed' => 'Add environmental claims or notes, or disable the environmental section if it is not relevant.',
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
        'title' => 'Usage instructions',
        'message' => 'Usage instructions are provided.',
        'passed' => 'Usage instructions are provided.',
        'failed' => 'Add customer-facing usage instructions.',
        'not_applicable' => 'The usage section is not enabled.',
    ],
    'dpp.care.instructions.present' => [
        'title' => 'Care instructions',
        'message' => 'Care instructions are provided.',
        'passed' => 'Care instructions are provided.',
        'failed' => 'Add care or maintenance instructions.',
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
        'title' => 'Repair instructions',
        'message' => 'Repair instructions are provided.',
        'passed' => 'Repair instructions are provided.',
        'failed' => 'Add repair instructions or explain why repair is not supported.',
        'not_applicable' => 'The repair section is not enabled.',
    ],
    'dpp.spare_parts.information.present' => [
        'title' => 'Spare parts information',
        'message' => 'Spare parts information is provided.',
        'passed' => 'Spare parts information is provided.',
        'failed' => 'Add spare parts availability or ordering information.',
        'not_applicable' => 'The spare parts section is not enabled.',
    ],
    'dpp.support.channel.present' => [
        'title' => 'Support contact channel',
        'message' => 'At least one support contact channel is provided.',
        'passed' => 'At least one support contact channel is provided.',
        'failed' => 'Add at least one support contact channel for customers.',
        'not_applicable' => 'The support section is not enabled.',
    ],
    'dpp.warranty.information.present' => [
        'title' => 'Warranty information',
        'message' => 'Warranty information is provided.',
        'passed' => 'Warranty information is provided.',
        'failed' => 'Add warranty terms or state that no warranty is provided.',
        'not_applicable' => 'The support section is not enabled.',
    ],
    'media.gallery.present' => [
        'title' => 'Product image gallery',
        'message' => 'The product has at least two images for the public gallery.',
        'passed' => 'The product has a gallery with at least two images.',
        'failed' => 'Upload at least two product images for the public gallery. This is a warning, not an activation blocker.',
    ],
    'media.variant_coverage' => [
        'title' => 'Images for active variants',
        'message' => 'All active variants have media.',
        'passed' => 'All active variants have media.',
        'failed' => 'Some active variants have no images. Open variants and add images to each active variant that needs its own visuals.',
    ],
    'documents.public_candidate.present' => [
        'title' => 'Public passport document',
        'message' => 'At least one document is marked as passport public.',
        'passed' => 'At least one document is marked as passport public.',
        'failed' => 'Mark at least one relevant document version as Passport public so it can be included in the published passport.',
    ],
    'documents.referenced.current_version' => [
        'title' => 'Document versions up to date',
        'message' => 'Referenced documents are at the latest version.',
        'passed' => 'All referenced documents are at the latest version.',
        'failed' => 'Some referenced documents have newer versions available.',
    ],
    'certificates.expiring_soon' => [
        'title' => 'Certificate expiry warning',
        'message' => 'No certificates are expiring within the warning period.',
        'passed' => 'No certificates are expiring within the warning period.',
        'failed' => 'Some certificates expire soon. Review them and upload renewed versions if available.',
    ],
    'certificates.no_expiration' => [
        'title' => 'Certificate expiry date',
        'message' => 'All certificates have an expiration date.',
        'passed' => 'All certificates have an expiration date.',
        'failed' => 'Some certificates have no expiration date. Add an expiry date when the certificate has one.',
    ],
    'certificates.declaration_present' => [
        'title' => 'Declaration of Conformity',
        'message' => 'A Declaration of Conformity has been linked.',
        'passed' => 'A Declaration of Conformity has been linked.',
        'failed' => 'No Declaration of Conformity document is linked. Upload or mark the correct document type in Product Documents.',
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
