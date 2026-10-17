<?php

return [
    'title' => 'Anwendbarkeit des digitalen Produktpasses',
    'intro' => 'Erfassen Sie die Produktklassifizierung und ihre Nachweise. Diese Bewertung bestimmt den Anwendungsbereich; die Bereitschaftspunktzahl bestätigt keine Rechtskonformität.',
    'market' => 'Zielmarkt', 'product_type' => 'Produktklassifizierung', 'capacity' => 'Batteriekapazität (kWh)',
    'level' => 'Ebene des Produktpasses', 'model_identifier' => 'Modellkennung', 'item_identifier' => 'Kennung der einzelnen Batterie',
    'source' => 'Nachweis oder Quelle der Klassifizierung', 'lifecycle_event' => 'Markt- oder Lebenszyklusereignis der Batterie',
    'market_event_date' => 'Datum des Markt- oder Lebenszyklusereignisses',
    'events' => ['initial_placement' => 'Erstmaliges Inverkehrbringen', 'putting_into_service' => 'Inbetriebnahme', 'remanufacturing' => 'Wiederaufarbeitung', 'repurposing' => 'Umnutzung'],
    'save' => 'Klassifizierung speichern', 'saved' => 'Klassifizierung und Anforderungen des Entwurfs aktualisiert. Prüfen Sie die neue Bereitschaftsbewertung.',
    'refresh' => 'Entwurf auf aktuelle Anforderungen aktualisieren',
    'refresh_help' => 'Die Anforderungen haben sich geändert. Die Aktualisierung erstellt eine neue Entwurfsrevision; frühere Bewertungen und veröffentlichte Pässe bleiben verfügbar.',
    'capacity_required' => 'Die Kapazität der Industriebatterie wird zur Prüfung der 2-kWh-Schwelle benötigt.',
    'source_required' => 'Geben Sie die zur Klassifizierung verwendeten Nachweise an.',
    'types' => ['electrical' => 'Elektroprodukt', 'ev_battery' => 'Elektrofahrzeugbatterie', 'lmt_battery' => 'Batterie für leichte Verkehrsmittel', 'industrial_battery' => 'Industriebatterie', 'portable_battery' => 'Gerätebatterie', 'other' => 'Sonstiges Produkt'],
    'status' => [
        'not_assessed' => 'Die Anwendbarkeit für dieses Produkt wurde noch nicht bewertet.',
        'invalid' => 'Die Klassifizierung ist unvollständig oder ungültig. Korrigieren Sie sie vor der Veröffentlichung.',
        'assessment_incomplete' => 'Erfassen Sie das Datum des Markt- oder Lebenszyklusereignisses. Ein früheres Inverkehrbringen und eine spätere Wiederaufarbeitung sind getrennt zu bewerten.',
        'sector_assessment_required' => 'Ein anwendbarer ESPR-Branchenrechtsakt muss gesondert ermittelt werden. Eine Einstufung als Elektroprodukt begründet allein keine Produktpasspflicht.',
        'not_applicable' => 'Die erfassten Angaben begründen keine EU-Batteriepasspflicht nach Artikel 77. Andere Produktpflichten erfordern eine gesonderte Bewertung.',
        'upcoming' => 'Der Anwendungsbereich des Batteriepasses wurde ermittelt: Die Pflicht beginnt am 18. Februar 2027 für Batterien, die ab diesem Datum in Verkehr gebracht oder in Betrieb genommen werden. Die Vorbereitung kann bereits erfolgen.',
        'mandatory' => 'Ein Pass für die einzelne Batterie ist erforderlich. Vollständige Abdeckung von Anhang XIII, eingeschränkter Zugang und Registeranbindung müssen vor einer regulatorischen Veröffentlichung überprüft werden.',
    ],
];
