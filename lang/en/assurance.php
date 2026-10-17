<?php

return [
    'title' => 'Publication, requirements and registration',
    'intro' => 'The score measures draft quality. Publication readiness, sector requirements and EU registration are separate assessments.',
    'stored' => 'Publication and sector statuses use the stored evaluation from :date. Recalculate before making a new publication decision.',
    'publication' => [
        'title' => 'Publication readiness',
        'not_ready' => 'Blocking issues must be resolved before publishing this draft.',
        'ready_with_warnings' => 'This draft can be published after acknowledging warnings. This does not verify sector compliance or registration.',
        'ready' => 'This draft passes the selected publication checks. This does not verify sector compliance or registration.',
    ],
    'requirements' => [
        'title' => 'Sector requirements',
        'not_assessed' => 'Applicability has not been assessed. Record the product classification and its evidence.',
        'assessment_required' => 'The applicable sector requirements have not been established. Complete the classification and select the applicable requirements.',
        'limited_scope_exception' => 'The recorded facts indicate an exception to the assessed battery passport scope. Other product obligations remain unassessed.',
        'coverage_unverified' => 'Scope has been identified, but complete sector evidence, access and lifecycle requirements have not been verified.',
    ],
    'registry' => [
        'title' => 'EU registration',
        'connection_pending' => 'Registry integration is pending. A public URL, QR code or local preparation is not a registration receipt.',
    ],
];
