# Draft for Fortnox Support — not sent

Ämne: Teknisk spårning av integrationslicens den 4 oktober 2026 — NordiPass, abonnemang 1853390 / test 1854146

Hej Tina,

Tack för att ni den 9 oktober tog bort integrationslicensen och krediterade fakturan. Vi vill nu fastställa den faktiska orsaken och förhindra att det händer igen. Vi begär ingen ny aktivering, beställning eller ändring av någon befintlig produktionskoppling.

Den 3 september konstaterade Support att NordiPass hade kopplats mot abonnemang 1853390 i stället för testabonnemang 1854146. Licensen togs bort den 7 september. Den 9 oktober skrev ni: “Vi kan se att ni la till integrationslicensen därefter på abonnemanget igen den 4 oktober.”

Vi kan inte bekräfta vilket konto som valdes manuellt den 4 oktober eller vilken åtgärd som beställde licensen. Vår applikation har inga anrop till ett API för att köpa en Fortnox-licens. Vi vill därför inte anta att OAuth eller tokenförnyelse automatiskt köper en licens.

Kan ni kontrollera er tekniska händelse-/beställningshistorik och besvara följande?

1. Vilken exakt tid lades licensen till den 4 oktober, inklusive tidszon eller UTC-tid? Ange gärna händelse-ID, beställningsreferens och licensens produktkod. Vi behöver inga betalningsuppgifter eller fullständiga fakturadetaljer.
2. Vem eller vilken systemprocess utförde åtgärden? Vilket användar-/aktörs-ID och vilken kanal användes: Tilläggsbeställning, Administrera användare/integrationer, marknadsplats, OAuth-behörighetsgodkännande, API, Support eller automatisk behandling?
3. Vilken exakt åtgärd och bekräftelse skapade den avgiftsbelagda licensen? Var det en ny beställning, återaktivering eller ett annat förlopp? Skilj gärna aktivering av en integrationskoppling från beställning av integrationslicensen.
4. Var OAuth-klienten/integrationen NordiPass kopplad till själva licenshändelsen? Om så är fallet, vilket registrerat Client ID/integrations-ID, vilket kundabonnemang och vilket behörighetsgodkännande hör till händelsen? Client Secret, access-/refresh-token och authorization-code ska inte skickas.
5. Kan en begäran till OAuth authorization-endpoint, ett godkännande av behörigheter, ett authorization-code-utbyte eller en refresh-token-begäran i sig lägga till en avgiftsbelagd integrationslicens på 1853390? Om ja: vid vilket steg, med vilka förutsättningar och vilken separat beställningsbekräftelse? Om nej: vilken beställningsväg visar loggen i just detta fall?
6. Bekräfta vad 1853390 respektive 1854146 identifierar i denna konfiguration: utvecklarportalens ägarabonnemang, OAuth-kopplingens kundabonnemang och testmiljö. Vi vill skilja integrationens registrerade Client ID från kundens API `tenantId`. Bekräfta också om samma registrerade integration får användas mot testkunden utan att beställa en licens på portalens ägarabonnemang.
7. Vad ändrades när licensen togs bort den 7 september, och vilken senare händelse bröt det tillståndet? Finns kvarvarande kopplingar eller inställningar som kan utlösa ytterligare en beställning? Vilken kontroll rekommenderar ni för att förhindra en ny avgiftsbelagd beställning utan ett separat uttryckligt godkännande?

För tidskorrelation visar våra lokala applikationsloggar anslutningsstart, callback och sparad koppling vid följande tider den 4 oktober i svensk tid:

| Lokal tid, Europe/Stockholm (UTC+02) | UTC | Händelse i NordiPass |
| --- | --- | --- |
| 00:43:20 och 00:43:33 | 3 okt 22:43:20 och 22:43:33 | Två anslutningsstarter, utan konsumerad callback-state. |
| 01:01:18 | 3 okt 23:01:18 | Anslutningsstart. |
| 01:01:29 | 3 okt 23:01:29 | Callback och lokalt sparad koppling. |
| 01:01:42 | 3 okt 23:01:42 | Lokal frånkoppling. |
| 01:01:43 och 01:01:47 | 3 okt 23:01:43 och 23:01:47 | Ny anslutningsstart respektive callback/sparad koppling. |
| Cirka 16:04:41–42 | 4 okt 14:04:41–42 | Befintliga lokala tokenuppgifter uppdaterades; den bevarade tokenens `tenantId` är 1854146. Tidigare acceptansrapport anger vanlig tokenförnyelse. |

De historiska anslutningshändelserna sparade inte Fortnox-abonnemanget eller valet av real/fake provider. De bevisar därför inte vilket Fortnoxkonto som valdes eller att två verkliga tokenutbyten genomfördes. Tokenen som bevarats är utfärdad senare och kan inte bevisa kontot vid de tidigare callback-händelserna.

NordiPass blockerar nu nya OAuth-starter på staging. Ett read-only-test med en redan giltig token den 9 oktober, 12:54:50 UTC, fungerade mot API tenant 1854146. Ingen ny auktorisering, tokenförnyelse eller licensbeställning utfördes vid testet.

Vänligen svara med händelseförlopp och förebyggande åtgärder utan att återaktivera eller beställa något. Tack!
