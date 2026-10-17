<?php

return [
    'mode' => 'Synkroniseringsläge', 'incremental' => 'Ändringar sedan vattenmärket', 'full' => 'Fullständig kataloggenomgång', 'unknown' => 'Inte registrerat',
    'pages' => 'Hämtade sidor', 'watermark' => 'Datavattenmärke', 'filter' => 'Ändringar sedan', 'unfiltered' => 'Alla synliga artiklar',
    'no_changes' => 'Inga ändringar sedan :date. Det betyder inte att Fortnox-katalogen är tom.',
    'empty_full' => 'Den fullständiga genomgången returnerade inga synliga artiklar.',
    'empty_unknown' => 'Inga artiklar returnerades. Filtret för denna äldre genomgång har inte registrerats.',
    'partial' => 'Genomgången är ofullständig. Fortsätt från sida :page; datavattenmärket har inte flyttats fram.',
    'failed' => 'Synkroniseringen misslyckades. Datavattenmärket har inte flyttats fram; granska felet och försök igen.',
    'running' => 'Synkroniseringen pågår. Datavattenmärket flyttas fram först när genomgången är klar.',
    'completed' => 'Fortnox-synkronisering klar: :fetched hämtade, :created skapade, :updated uppdaterade, :skipped överhoppade, :conflicts konflikter.',
    'preview_button' => 'Förhandsgranska hela katalogen', 'preview_title' => 'Förhandsgranskning av hela katalogen',
    'preview_intro' => 'Genomgången läser alla synliga Fortnox-artiklar. Den ändrar inte katalogprodukter, kopplingar eller datavattenmärket.',
    'total' => 'Synliga artiklar', 'mapped' => 'Artiklar med befintlig koppling', 'unmapped' => 'Artiklar utan koppling',
    'preview_note' => 'Antalen är en förhandsgranskning, inte garanterade antal skapade eller uppdaterade produkter. Katalogen kan ändras före import. Befintliga kopplingar, lokala åsidosättningar och konfliktkontroller bevaras.',
    'confirm' => 'Importera hela katalogen', 'cancel' => 'Tillbaka till integrationen',
    'test_only' => 'Förhandsgranskning och import av hela katalogen är tills vidare endast tillgängliga i testmiljön.',
];
