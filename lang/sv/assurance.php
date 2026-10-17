<?php

return [
    'title' => 'Publicering, krav och registrering',
    'intro' => 'Poängen mäter utkastets kvalitet. Publiceringsberedskap, sektorskrav och EU-registrering bedöms separat.',
    'stored' => 'Publicerings- och sektorsstatus bygger på den sparade utvärderingen från :date. Utvärdera igen före ett nytt publiceringsbeslut.',
    'publication' => [
        'title' => 'Publiceringsberedskap',
        'not_ready' => 'Blockerande problem måste åtgärdas innan utkastet publiceras.',
        'ready_with_warnings' => 'Utkastet kan publiceras efter att varningarna har bekräftats. Detta verifierar inte sektorsefterlevnad eller registrering.',
        'ready' => 'Utkastet klarar de valda publiceringskontrollerna. Detta verifierar inte sektorsefterlevnad eller registrering.',
    ],
    'requirements' => [
        'title' => 'Sektorskrav',
        'not_assessed' => 'Tillämpligheten har inte bedömts. Ange produktklassificering och underlag.',
        'assessment_required' => 'Tillämpliga sektorskrav har inte fastställts. Slutför klassificeringen och välj tillämpliga krav.',
        'limited_scope_exception' => 'Uppgifterna visar ett undantag från det bedömda batteripasskravet. Andra produktkrav har inte bedömts.',
        'coverage_unverified' => 'Omfattningen har identifierats, men fullständiga sektorsunderlag, åtkomst och livscykelkrav har inte verifierats.',
    ],
    'registry' => [
        'title' => 'EU-registrering',
        'connection_pending' => 'Registerintegrationen återstår. En publik URL, QR-kod eller lokal förberedelse är inte ett registreringskvitto.',
    ],
];
