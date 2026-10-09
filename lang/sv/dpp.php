<?php

return [
    'title' => 'DPP-tillämplighet',
    'intro' => 'Registrera produktens klassificering och underlag. Bedömningen identifierar tillämpningsområdet; beredskapspoängen intygar inte rättslig efterlevnad.',
    'market' => 'Målmarknad', 'product_type' => 'Produktklassificering',
    'capacity' => 'Batterikapacitet (kWh)', 'level' => 'Passnivå',
    'model_identifier' => 'Modellidentifierare', 'item_identifier' => 'Identifierare för det enskilda batteriet',
    'source' => 'Klassificeringsunderlag eller källa', 'save' => 'Spara klassificering',
    'lifecycle_event' => 'Batteriets marknads- eller livscykelhändelse', 'market_event_date' => 'Datum för marknads- eller livscykelhändelsen',
    'events' => [
        'initial_placement' => 'Första utsläppande på marknaden', 'putting_into_service' => 'Ibruktagande',
        'remanufacturing' => 'Återtillverkning', 'repurposing' => 'Ändrad användning',
    ],
    'saved' => 'Utkastets klassificering och krav har uppdaterats. Granska det nya resultatet.',
    'refresh' => 'Uppdatera utkastet till aktuella krav',
    'refresh_help' => 'Kraven har ändrats. Uppdateringen skapar en ny utkastrevision; tidigare bedömningar och publicerade pass finns kvar.',
    'capacity_required' => 'Industribatteriets kapacitet behövs för att bedöma gränsen på 2 kWh.',
    'source_required' => 'Ange underlaget för produktklassificeringen.',
    'types' => [
        'electrical' => 'Elprodukt', 'ev_battery' => 'Elfordonsbatteri', 'lmt_battery' => 'Batteri för lätta transportmedel',
        'industrial_battery' => 'Industribatteri', 'portable_battery' => 'Bärbart batteri', 'other' => 'Annan produkt',
    ],
    'status' => [
        'not_assessed' => 'Produktens tillämplighet har inte bedömts.',
        'invalid' => 'Klassificeringen är ofullständig eller ogiltig. Korrigera den före publicering.',
        'assessment_incomplete' => 'Registrera datumet för batteriets marknads- eller livscykelhändelse. Tidigare utsläppande och senare återtillverkning behöver bedömas separat.',
        'sector_assessment_required' => 'Tillämplig sektorsakt enligt ESPR måste identifieras separat. En elproduktklassificering fastställer inte i sig ett obligatoriskt DPP.',
        'not_applicable' => 'Registrerade fakta fastställer inte en EU-batteripasskyldighet enligt artikel 77. Andra produktkrav behöver bedömas separat.',
        'upcoming' => 'Batteripassets tillämpningsområde har identifierats: skyldigheten börjar den 18 februari 2027 för batterier som släpps ut på marknaden eller tas i bruk från det datumet.',
        'mandatory' => 'Ett pass för det enskilda batteriet krävs. Full täckning av bilaga XIII, begränsad åtkomst och registerintegration måste verifieras före regulatorisk publicering.',
    ],
];
