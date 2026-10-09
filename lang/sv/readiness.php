<?php

return [
    'label' => 'Beredskap',
    'dpp.requirement' => ['title' => 'Profilkrav', 'deferred' => 'Kravet ligger utanför sina tillämpningsdatum.'],
    'regulatory.profile' => [
        'title' => 'Aktuella regulatoriska krav',
        'stale' => 'Den regulatoriska profilen har ändrats eller är inte tillgänglig. Uppdatera och granska utkastet igen.',
    ],
    'regulatory.battery' => [
        'title' => 'Batteripassets täckning',
        'incomplete' => 'Artikel 77 är tillämplig. Full täckning av bilaga XIII, begränsad åtkomst och EU-registerregistrering har inte verifierats i denna implementation.',
    ],
    'regulatory.context' => [
        'invalid' => 'Korrigera den ogiltiga produktklassificeringen före publicering.',
        'date_missing' => 'Registrera datumet för marknads- eller livscykelhändelsen för att bedöma batteripassets tillämplighet.',
    ],
];
