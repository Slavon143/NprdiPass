# Real public supplier data acquired — 2026-10-08

The supplier-source discovery subitem is complete: five unchanged original files were downloaded from manufacturer-controlled HTTPS URLs, with local SHA-256 manifests and successful XLSX/ZIP CRC/PDF signature checks. No account, purchase, subscription, external message, application mutation or staging import was performed. These are real public manufacturer catalogues/documents, not generated test rows or a customer-specific ERP feed. Import/profile/evidence acceptance remains open.

## Available originals

Local directory: `D:/laravel/nordipass/storage/app/supplier-acceptance/public-20261008/`.

| Original file | Actual inspected content |
| --- | --- |
| `hager_artikelstamm_2026-10.xlsx` | 15137 article/name records on the main sheet; 26 headers. |
| `berker_artikelstamm_2026-10.xlsx` | 3592 article/name records on the main sheet; 25 headers. |
| `elcom_artikelstamm_2026-10.xlsx` | 405 article/name records on the main sheet; 25 headers. |
| `elcom_artikelstamm_2026-10_bmecat_etim10_260923.zip` | One 6050927-byte XML catalogue; BMEcat 2005 in the ETIM namespace, 413 PRODUCT/ARTICLE elements. |
| `elko-EKO03956.pdf` | Original Swedish ELKO technical sheet for EKO03956, acquired as PDF. |

The three XLSX files total **19134 real product records**. Every main-sheet product has a nonempty EAN with a valid independent GTIN checksum. There are zero duplicate article values or nonempty EAN values **within each file**; cross-file collisions were not certified. All inspected worksheet formula-cell counts are zero. Counts exclude the title/header rows and secondary configurator lists; those lists are not counted as additional products.

Primary source: [Hager article master data and prices](https://hager.com/de/service/downloads/artikelstamm-und-preise). Its October links were followed to the original assets.sc.hager.com files. All three workbooks explicitly identify `Artikelstamm 2026 / Stand 01.10.2026` in row 1. The [ELKO source PDF](https://www.elko.se/getfile.php/136208-1712463602/ELKO.se/Produkter%20PIM/EKO03/SE-EKO03956/documents-custom/FDVdok3956.pdf) comes directly from the official Swedish site; availability alone is not evidence of current legal compliance or document approval.

Exact URLs, byte sizes and hashes for all five originals are in `source-manifest.json`; inspected worksheet/header/count/identifier diagnostics are in `inspection.json`; verified hashes/CRC/signature checks are in `integrity-checks.json`. The read-only inspection script is retained alongside them. Original source bytes were not repaired, renamed internally or re-exported.

## Import preparation and domain boundaries

The XLSX main sheet has a title in row 1, **headers in row 2**, and data starting in row 3. Do not silently treat the title or header as a product. The second worksheet lists configurator article references and has a different structure. Native preview must use a deliberate header/sheet strategy or a separately labelled derived import copy while preserving these originals.

| Source header | Candidate NordiPass target / boundary |
| --- | --- |
| `Bestellnummer` | Manufacturer article reference, suitable for `mpn`; a tenant's SKU policy must be chosen explicitly before also mapping to `sku`. |
| `Bezeichnung` | `name`, preserving the source German description. |
| `EAN` | `gtin`, retained as an identifier, not a floating-point quantity. |
| Brand | Explicit per-file manufacturer/brand mapping: Hager, Berker or Elcom. |
| `N-Gew.`, `B-Gew.`, `GE` | Net/gross weight with its source unit (`G` or `KG`); never silently assume grams. |
| `Länge`, `Breite`, `Höhe`, `Abm.` | Dimensions and their source unit, via reviewed compatible attributes. |
| `Bruttopreis`, `PE` | Source gross list price and price unit; not an authorized actual sale/Stripe price. |
| `Hinweis` (for example `Abverkauf`) | Source metadata; does not authorize activation/archive/delete. |
| BMEcat ETIM/ECLASS classifications | Evidence for proposed category mapping; not automatic DPP applicability or a legal ruleset. |

The Elcom XML has 413 product elements versus 405 XLSX records. Treat these as separate originals; reconcile coverage/period identifiers before merging rather than claiming an identical catalogue. The XLSX title provides a period label; a file timestamp is not automatically a trusted sync watermark.

Next acceptance work is header/sheet handling, native isolated preview/apply/repeat/export, retained documents, category/profile mapping and readiness/publication guards. No DPP readiness or full supplier acceptance gate is closed by acquisition/checksum verification alone.

## Discovery failures retained

Stripe Directory could not be used because its CLI could not access its local config; no account/config repair or authentication was attempted. Discovery continued through primary public manufacturer pages. Schneider Electric's official public XLSX listings were found ([Belgium](https://www.se.com/be/fr/download/document/BE-Pricelist-20260501/), [Netherlands](https://www.se.com/nl/nl/download/document/prijslijst/)), but the direct page fetch returned Access Denied; those workbooks are not claimed as downloaded.

One live HTML response from Hager still contained September XLSX links that returned Document Not Found. The independently observed October links on the official page succeeded; failed September requests are not counted as acquisition. The retained raw HTML is therefore a stale discovery response, not proof of October file links. No paid API or login-only BMEcat download was used.
