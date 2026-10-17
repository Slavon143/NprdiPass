<?php

return [
    'title' => 'Veröffentlichung, Anforderungen und Registrierung',
    'intro' => 'Die Punktzahl bewertet die Qualität des Entwurfs. Veröffentlichungsbereitschaft, Branchenanforderungen und EU-Registrierung werden getrennt beurteilt.',
    'stored' => 'Die Veröffentlichungs- und Branchenstatus beruhen auf der gespeicherten Bewertung vom :date. Berechnen Sie die Bewertung vor einer neuen Veröffentlichungsentscheidung erneut.',
    'publication' => [
        'title' => 'Veröffentlichungsbereitschaft',
        'not_ready' => 'Blockierende Probleme müssen vor der Veröffentlichung dieses Entwurfs behoben werden.',
        'ready_with_warnings' => 'Dieser Entwurf kann nach Bestätigung der Warnungen veröffentlicht werden. Dies bestätigt weder die Erfüllung der Branchenanforderungen noch eine Registrierung.',
        'ready' => 'Dieser Entwurf besteht die gewählten Veröffentlichungsprüfungen. Dies bestätigt weder die Erfüllung der Branchenanforderungen noch eine Registrierung.',
    ],
    'requirements' => [
        'title' => 'Branchenanforderungen',
        'not_assessed' => 'Die Anwendbarkeit wurde noch nicht bewertet. Erfassen Sie die Produktklassifizierung und ihre Nachweise.',
        'assessment_required' => 'Die anwendbaren Branchenanforderungen wurden noch nicht festgelegt. Vervollständigen Sie die Klassifizierung und wählen Sie die anwendbaren Anforderungen.',
        'limited_scope_exception' => 'Die erfassten Angaben weisen auf eine Ausnahme vom geprüften Anwendungsbereich des Batteriepasses hin. Andere Produktpflichten bleiben ungeprüft.',
        'coverage_unverified' => 'Der Anwendungsbereich wurde ermittelt. Die vollständigen Branchennachweise sowie Zugangs- und Lebenszyklusanforderungen wurden jedoch noch nicht überprüft.',
    ],
    'registry' => [
        'title' => 'EU-Registrierung',
        'connection_pending' => 'Die Registeranbindung steht noch aus. Eine öffentliche URL, ein QR-Code oder eine lokale Vorbereitung ist keine Registrierungsbestätigung.',
    ],
];
