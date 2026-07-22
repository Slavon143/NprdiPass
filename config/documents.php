<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document Storage Disk
    |--------------------------------------------------------------------------
    */

    'disk' => env('DOCUMENTS_DISK', 'product_documents'),

    /*
    |--------------------------------------------------------------------------
    | Maximum File Size (KB)
    |--------------------------------------------------------------------------
    |
    | Pilot limit: 25 MiB = 25600 KB
    |
    */

    'max_size_kb' => (int) env('DOCUMENTS_MAX_SIZE_KB', 25600),

    /*
    |--------------------------------------------------------------------------
    | Certificate Expiry Warning (Days)
    |--------------------------------------------------------------------------
    */

    'expiry_warning_days' => (int) env('DOCUMENTS_EXPIRY_WARNING_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Review Defaults
    |--------------------------------------------------------------------------
    */

    'auto_approve_new_versions' => (bool) env('DOCUMENTS_AUTO_APPROVE_NEW_VERSIONS', false),
    'creator_self_approval_allowed' => (bool) env('DOCUMENTS_CREATOR_SELF_APPROVAL_ALLOWED', false),

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    */

    'allowed_mime_types' => ['application/pdf'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Extensions
    |--------------------------------------------------------------------------
    */

    'allowed_extensions' => ['pdf'],

    /*
    |--------------------------------------------------------------------------
    | Canonical Document Type Registry
    |--------------------------------------------------------------------------
    */

    'types' => [
        'general_document' => [
            'title' => 'General Document',
            'description' => 'Internal or supporting product document.',
            'required_metadata' => [],
            'allowed_metadata' => ['reference_url', 'notes'],
            'default_visibility' => 'internal',
            'expiry_support' => false,
            'review_required' => false,
            'approval_required' => false,
            'readiness_mappings' => ['documents.references.valid'],
        ],
        'manual' => [
            'title' => 'Manual',
            'description' => 'Use, care, installation or operation manual.',
            'required_metadata' => [],
            'allowed_metadata' => ['applicable_market', 'reference_url'],
            'default_visibility' => 'passport_public',
            'expiry_support' => false,
            'review_required' => false,
            'approval_required' => false,
            'readiness_mappings' => ['documents.public_candidate.present'],
        ],
        'technical_specification' => [
            'title' => 'Technical Specification',
            'description' => 'Technical data or specification sheet.',
            'required_metadata' => [],
            'allowed_metadata' => ['standard_reference', 'applicable_market'],
            'default_visibility' => 'passport_public',
            'expiry_support' => false,
            'review_required' => true,
            'approval_required' => true,
            'readiness_mappings' => ['documents.file.metadata.valid'],
        ],
        'safety_data' => [
            'title' => 'Safety Data',
            'description' => 'Safety data and handling evidence.',
            'required_metadata' => [],
            'allowed_metadata' => ['standard_reference', 'applicable_market'],
            'default_visibility' => 'passport_public',
            'expiry_support' => true,
            'review_required' => true,
            'approval_required' => true,
            'readiness_mappings' => ['documents.file.available'],
        ],
        'declaration_of_conformity' => [
            'title' => 'Declaration of Conformity',
            'description' => 'Declaration metadata and source file.',
            'required_metadata' => ['issuer_name', 'issue_date'],
            'allowed_metadata' => ['declaration_identifier', 'declaration_type', 'applicable_market', 'standard_reference', 'signatory_name', 'signatory_title', 'reference_url'],
            'default_visibility' => 'passport_public',
            'expiry_support' => true,
            'review_required' => true,
            'approval_required' => true,
            'readiness_mappings' => ['certificates.declaration.present', 'certificates.metadata.complete'],
        ],
        'certificate' => [
            'title' => 'Certificate',
            'description' => 'Certificate metadata and source file.',
            'required_metadata' => ['issuer_name', 'issue_date', 'certificate_number'],
            'allowed_metadata' => ['issuing_body', 'applicable_market', 'standard_reference', 'scope', 'reference_url'],
            'default_visibility' => 'passport_public',
            'expiry_support' => true,
            'review_required' => true,
            'approval_required' => true,
            'readiness_mappings' => ['certificates.metadata.complete', 'certificates.not_expired'],
        ],
        'test_report' => [
            'title' => 'Test Report',
            'description' => 'Laboratory or technical test report.',
            'required_metadata' => ['issuer_name', 'issue_date'],
            'allowed_metadata' => ['evidence_type', 'standard_reference', 'reference_url'],
            'default_visibility' => 'internal',
            'expiry_support' => true,
            'review_required' => true,
            'approval_required' => true,
            'readiness_mappings' => ['documents.file.metadata.valid'],
        ],
        'environmental_evidence' => [
            'title' => 'Environmental Evidence',
            'description' => 'Provided environmental evidence.',
            'required_metadata' => [],
            'allowed_metadata' => ['evidence_type', 'topic_code', 'issuer_name', 'reference_url'],
            'default_visibility' => 'passport_public',
            'expiry_support' => true,
            'review_required' => true,
            'approval_required' => true,
            'readiness_mappings' => ['documents.public_candidate.present'],
        ],
        'compliance_evidence' => [
            'title' => 'Compliance Evidence',
            'description' => 'Provided compliance evidence.',
            'required_metadata' => [],
            'allowed_metadata' => ['evidence_type', 'topic_code', 'issuer_name', 'reference_url'],
            'default_visibility' => 'internal',
            'expiry_support' => true,
            'review_required' => true,
            'approval_required' => true,
            'readiness_mappings' => ['documents.file.metadata.valid'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'per_page' => (int) env('DOCUMENTS_PER_PAGE', 25),
    'max_per_page' => (int) env('DOCUMENTS_MAX_PER_PAGE', 100),

];
