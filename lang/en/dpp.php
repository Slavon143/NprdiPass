<?php

return [
    'title' => 'DPP applicability',
    'intro' => 'Record the product classification and its evidence. This assessment identifies scope; the readiness score does not certify legal compliance.',
    'market' => 'Target market',
    'product_type' => 'Product classification',
    'capacity' => 'Battery capacity (kWh)',
    'level' => 'Passport level',
    'model_identifier' => 'Model identifier',
    'item_identifier' => 'Individual battery identifier',
    'source' => 'Classification evidence or source',
    'lifecycle_event' => 'Battery market or lifecycle event',
    'market_event_date' => 'Market or lifecycle event date',
    'events' => [
        'initial_placement' => 'First placement on the market', 'putting_into_service' => 'Putting into service',
        'remanufacturing' => 'Remanufacturing', 'repurposing' => 'Repurposing',
    ],
    'save' => 'Save classification',
    'saved' => 'Draft classification and requirements updated. Review the new readiness result.',
    'refresh' => 'Update draft to current requirements',
    'refresh_help' => 'The requirements have changed. Updating the draft creates a new revision; earlier evaluations and published passports remain available.',
    'capacity_required' => 'Industrial battery capacity is required to assess the 2 kWh threshold.',
    'source_required' => 'Provide the evidence used to classify the product.',
    'types' => [
        'electrical' => 'Electrical product', 'ev_battery' => 'Electric vehicle battery',
        'lmt_battery' => 'Light means of transport battery', 'industrial_battery' => 'Industrial battery',
        'portable_battery' => 'Portable battery', 'other' => 'Other product',
    ],
    'status' => [
        'not_assessed' => 'Product applicability has not been assessed.',
        'invalid' => 'The classification is incomplete or invalid. Correct it before publication.',
        'assessment_incomplete' => 'Record the battery market or lifecycle event date. Earlier placement and later remanufacturing must be assessed separately.',
        'sector_assessment_required' => 'An applicable ESPR sector act must be identified separately. An electrical classification alone does not establish a mandatory DPP.',
        'not_applicable' => 'The recorded facts do not establish an EU battery passport obligation under Article 77. Other product obligations require a separate assessment.',
        'upcoming' => 'Battery passport scope identified: the obligation starts on 18 February 2027 for batteries placed on the market or put into service from that date. Preparation may continue now.',
        'mandatory' => 'A passport for the individual battery is required. Complete Annex XIII coverage, restricted access and registry integration must be verified before regulatory publication.',
    ],
];
