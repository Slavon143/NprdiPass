# Ревью блокеров NordiPass и испытание реальных товаров ABB

Дата проверки 13 октября 2026 года. На staging импортированы и опубликованы 10 реальных моделей ABB S201. У всех паспортов рабочие HTTPS-страницы, фотографии производителя и независимо декодированные QR. Результат публикации — `ready_with_warnings`, 87 баллов, 0 блокеров и 18 предупреждений. Это балл внутренней готовности NordiPass, а не процент нормативного соответствия.

Компания аудита `ABB S201 supplier audit 2026-10-13` принадлежит тому же существующему владельцу, что и проверенная компания Fortnox. В переключателе компаний staging нужно выбрать эту компанию, чтобы увидеть товары и задания импорта. Её UUID: `014e052f-12c7-4827-976d-be3f569fc112`; import job: `c9b8d8a0-965b-4a7e-be9d-2ffd71e368a2`.

## Основные результаты

| Проверка | Результат |
| --- | --- |
| Импорт из данных настоящего XLSX ABB | 10 valid, 0 invalid, 10 created |
| Managed products и опубликованные версии | 10 товаров, у каждого версия 1 |
| Публичная страница без авторизации | 10 из 10 HTTP 200 |
| Фото из опубликованного снимка | 10 из 10 HTTP 200; SHA256 совпадает с файлом ABB |
| Независимое чтение QR через ZXing | 10 из 10 ведут на правильные staging URL |
| Повторный запуск | Без дублей; UUID и checksum публикаций сохранены |
| Документы производителя | 10 копий одного family guide; internal, pending_review, approval pending |
| Подготовка регистрации в ЕС | 10 blocked, без registration URI |
| Проверки локальной полной цепочки | 1 тест, 102 assertions, passed |
| Проверки scope, guidance, профилей и registry | 56 тестов, 258 assertions, passed |
| 71 пункт guidance и три категории батарей | 213 статусов сравнены; расхождений в категориях 0 |

## Что требуют ESPR и источники Комиссии

ESPR задаёт рамку DPP. Набор данных, уровень model/batch/item, права доступа и сроки для группы продукции определяются применимыми актами. Нельзя превращать общий checklist NordiPass в утверждение о полном соответствии для любой электротехники. Для этой партии автоматов применимый отраслевой DPP-акт не выбран; приложение корректно показывает `sector_assessment_required`. [ESPR Articles 4, 9–12 и Annex III](https://eur-lex.europa.eu/eli/reg/2024/1781/oj), [страница DPP Комиссии](https://single-market-economy.ec.europa.eu/single-market/digital-product-passport_en).

Для EV и LMT батарей, а также industrial batteries строго свыше 2 kWh, Article 77 вводит паспорт с 18 февраля 2027 года при выводе на рынок или вводе в эксплуатацию. Промышленная батарея ровно 2 kWh не попадает под этот порог. Требуется информация модели и конкретной батареи; режим доступа различается. [Battery Regulation Article 77 и Annex XIII](https://eur-lex.europa.eu/eli/reg/2023/1542/2025-07-31/eng/pdf).

Актуальный FAQ Комиссии разделяет готовую работоспособную батарею и компоненты. Паспорт может относиться к единой системе с общим BMS; нельзя просто суммировать независимые батареи и выбирать профиль по сумме. При remanufacturing/repurposing нужна новая история паспорта и связь с предыдущим. Пустые данные эксплуатации новой батареи и обязательные исходные показатели — разные случаи. Частота обновления зависит от данных и событий жизненного цикла. Язык определяется рынком и требованиями государства. [Battery FAQ, edition September 2026, sections 3, 7–9](https://single-market-economy.ec.europa.eu/single-market/digital-product-passport/eu-digital-product-passport-faq-batteries_en).

Registry Implementing Regulation (EU) 2026/1778 уже опубликован: регистрация требует проверенного оператора, а паспорт — семантической структуры и корректной granularity. Реестр и хостинг паспорта выполняют разные функции. В области действия акта упомянуты также construction products, toys, detergents/surfactants; это не означает одновременную обязанность для всех таких товаров сегодня. Их применимые даты и подкатегории нужно включать отдельными профилями. [Regulation 2026/1778, Articles 1–4, 8–10](https://eur-lex.europa.eu/legal-content/EN/TXT/?qid=1789778494858&uri=CELEX%3A32026R1778).

Комиссия также опубликовала ссылки на шесть гармонизированных стандартов DPP — EN 18216, 18219, 18220, 18221, 18222, 18223:2026. Наличие Laravel API или QR само по себе не подтверждает выполнение этих стандартов. [Decision 2026/1736](https://eur-lex.europa.eu/legal-content/EN/TXT/PDF/?uri=CELEX%3A32026D1736).

Встроенный справочник соответствует edition 2.0 от 15 августа 2026 года: 71 строка, страницы 5–14. Все 213 статусов трёх колонок сверены с оригиналом PDF. Справочник рекомендательный и не заменяет нормативные акты. [Commission Battery Passport data points](https://single-market-economy.ec.europa.eu/document/download/cd1e5e6c-4a4a-4b99-995a-49eb6916187e_en?filename=Digital+Batteries+Passport+-+data+point+by+category.pdf).

## Реальные исходные данные и границы импорта

Источник — [электронный каталог ABB для machinery/switchgear](https://new.abb.com/low-voltage/de/elektronische-kataloge/maschinenbau-schaltanlagenbau), [архив Excel, document 2CDC001171P0101](https://search.abb.com/library/Download.aspx?Action=Launch&DocumentID=2CDC001171P0101&DocumentPartId=&LanguageCode=de). Дата набора 1 октября 2026 года; прайс начинает действовать 1 ноября 2026 года. Цены в тестовые паспорта не включались.

Оригинальный XLSX содержит 37 494 строки данных и 100 колонок. Предыдущая проверка прочитала файл полностью через reader приложения; оригинальный XLSX принимается загрузкой. Внешний ZIP текущий контракт отвергает: его нужно распаковать. Все 37 494 строки в базу не загружались.

Для этого испытания выбраны десять строк оригинала. В базу применён CSV с семью полями `name, slug, brand, manufacturer, mpn, gtin, description`, полученными из XLSX. Это воспроизводимое преобразование настоящего источника; оно не является утверждением о прямом применении всего ABB workbook. Модели и номера строк приведены ниже. Синтетические товары в эту десятку не входят.

Описание производителя сохранено в каталоге на немецком. Публичное английское описание составлено из номинала, полюсности, характеристики и breaking capacity исходной строки, с явной маркировкой pilot. Страна происхождения DE не подставлена вместо адреса юридического производителя или оператора. Показатели состава, углеродного следа, декларации, сертификаты и серийные номера не выдумывались.

Настоящий документ [ABB S200 End of Life Instruction 9AKK108469A7114 Rev C](https://search.abb.com/library/Download.aspx?DocumentID=9AKK108469A7114&LanguageCode=en&DocumentPartId=&Action=Launch), пять страниц, дата 28 июня 2024 года, загружен каждому товару как recycling_guide. Это руководство семейства S200; его достаточность и применимость к конкретной модели требуют проверки. PDF отправлен на review; в компании один участник, поэтому независимое approval не выполнено. Документ не включён в публичный snapshot. Публичный текст сообщает этот статус. Для встраивания файла нужен другой реальный проверяющий, решение и новая публикация.

| Артефакт | SHA256 |
| --- | --- |
| Оригинальный ABB ZIP | `f5f2d54589e0eca3a771b67893d9fe24fc34dde1349cd471ac8181e0d8103af4` |
| Оригинальный ABB XLSX | `fec8bc74ffe64d2baf0e51ac459504e55e150cc761248e16d1d8e42f335c5573` |
| ABB EoL Rev C PDF | `8f1c0eb23ed72e506c5288c9d160c2726fbcf4bf544b831f11b00d50fcbe07a8` |
| Commission guidance PDF | `e045a7668015520f4c353e72fb57a89bdd62baaf0a98e6f4aefc7f5f42819eb7` |

## Десять опубликованных паспортов

| Модель | MPN | GTIN | Строка XLSX | Публикация |
| --- | --- | --- | --- | --- |
| S201-C6 | 2CDS251001R0064 | 4016779464000 | 24616 | [Паспорт](https://staging.nordipass.nu.codecrafters.nu/p/01a135e9-e025-70a5-a847-36959124cb04) |
| S201-C10 | 2CDS251001R0104 | 4016779464208 | 24586 | [Паспорт](https://staging.nordipass.nu.codecrafters.nu/p/01a135e9-e526-70d3-87d8-d6dd796f1b8d) |
| S201-C16 | 2CDS251001R0164 | 4016779464406 | 24595 | [Паспорт](https://staging.nordipass.nu.codecrafters.nu/p/01a135e9-e85e-729b-a5c0-d9fcc28ead61) |
| S201-C20 | 2CDS251001R0204 | 4016779464505 | 24601 | [Паспорт](https://staging.nordipass.nu.codecrafters.nu/p/01a135e9-eb61-72b9-8986-67075739eee0) |
| S201-C25 | 2CDS251001R0254 | 4016779464604 | 24603 | [Паспорт](https://staging.nordipass.nu.codecrafters.nu/p/01a135e9-ee70-721c-a207-133957706d1b) |
| S201-C32 | 2CDS251001R0324 | 4016779464703 | 24607 | [Паспорт](https://staging.nordipass.nu.codecrafters.nu/p/01a135e9-f1b6-7184-bc0c-89dbc0e38f22) |
| S201-B6 | 2CDS251001R0065 | 4016779464901 | 24574 | [Паспорт](https://staging.nordipass.nu.codecrafters.nu/p/01a135e9-f4e2-7327-9051-28266766a773) |
| S201-B10 | 2CDS251001R0105 | 4016779463805 | 24549 | [Паспорт](https://staging.nordipass.nu.codecrafters.nu/p/01a135e9-f7e1-70d8-a3f9-5641803415ad) |
| S201-B16 | 2CDS251001R1165 | 4016779578639 | 24557 | [Паспорт](https://staging.nordipass.nu.codecrafters.nu/p/01a135e9-fa95-71f6-b911-086763e69e9e) |
| S201-B20 | 2CDS251001R0205 | 4016779465106 | 24561 | [Паспорт](https://staging.nordipass.nu.codecrafters.nu/p/01a135e9-fdac-7107-ba3e-1d83b5abdad1) |

Для всех десяти: до заполнения 67 баллов, 8 блокеров и 18 предупреждений; перед публикацией 87 баллов, 0 блокеров и 18 предупреждений. Все опубликованы с явным acknowledgement предупреждений. Версия 1 и рабочий draft дают две записи версии на паспорт. Проверка после повторных запусков подтвердила исходные UUID и checksum опубликованных версий, один документ и одну registry preparation на каждый товар.

Первоначальные блокеры: default-language translations, описание, контакт производителя, safety content, recycling instructions и три проверки primary media. Они закрыты через существующие actions: импорт → managed → draft → EN → regulatory_context electrical/model → media → document → заполнение секций → readiness → publication → snapshot → QR → публичная страница → acceptance registry preparation. Review документа отправлен отдельно и не меняет опубликованный снимок.

Оставшиеся 18 предупреждений относятся к category, стране производителя, ответственному оператору, emergency/storage information, recycling codes, take-back, materials, environmental claims/metrics, usage/care instructions, repairability, support/warranty, gallery, public documents и declaration. Некоторые требуют источника; некоторые должны быть N/A по профилю. Нельзя повышать балл, придумывая environmental claim, warranty или repairability.

Основная цепочка проверена через бизнес-actions и HTTP; браузер дополнительно подтвердил реальную публичную страницу S201-C6 с фотографией и маркировкой pilot. Полный click-through всех форм владельца и физическое сканирование телефоном в этот результат не входят. QR декодирован независимо из PNG через ZXing.

## Все 33 настроенных блокера

В конфигурации 66 правил: 33 Blocker, 32 Warning, 1 Recommendation. Таблица ниже показывает фактическую проверку каждого Blocker. Нормативные источники не объявляют этот список универсальным комплектом DPP; в большинстве строк проверяется внутренняя целостность приложения. Текст «прошёл» означает выполнение указанного условия.

| Код | Фактическая проверка | Оценка |
| --- | --- | --- |
| `catalog.product.exists` | Товар принадлежит текущей компании. | Граница tenant isolation. Сохранить всегда; это не доказательство законности товара. |
| `catalog.product.active` | Статус товара не Archived; Draft проходит. | Внутренний жизненный цикл. Название active шире фактического условия. |
| `catalog.product.name.present` | Есть имя каталога или публичное имя паспорта. | Дублирует dpp.identity.name.present; нельзя дважды считать независимым доказательством. |
| `catalog.product.identifier.present` | Есть хотя бы SKU, GTIN или MPN. | Внутренний минимум. Не проверяет UPI, уникальность экземпляра или стандарты идентификаторов. |
| `passport.exists` | Есть паспорт в контексте товара. | Внутренняя целостность. |
| `passport.status.editable` | Draft, Published или Unpublished. | Разрешение редактирования; не нормативный статус. |
| `passport.current_draft.exists` | Есть текущий черновик. | Внутренняя целостность. |
| `passport.current_draft.belongs_to_passport` | Черновик относится к нужному паспорту и tenant. | Критическая граница владения. |
| `passport.current_draft.status` | Текущая версия имеет статус Draft. | Защита жизненного цикла и опубликованного снимка. |
| `passport.schema.supported` | Поддерживается внутренняя schema version 1. | Это схема NordiPass; не схема semantic repository ЕС. |
| `passport.payload.valid` | Payload проходит внутренний валидатор DPP. | Проверка формата, типов и разрешённых ключей; не полнота отраслевого набора. |
| `passport.payload.size` | JSON кодируется и не превышает 1 048 576 байт. | Техническое ограничение продукта; не лимит из ESPR. |
| `passport.default_language.enabled` | Для default language существует ключ translations. | Название не отражает полноту перевода. Нужна отдельная проверка обязательных текстов по рынку. |
| `passport.core_sections.enabled` | Включены четыре core-раздела NordiPass. | Общий pilot-профиль; перечень не становится отраслевым законом. |
| `passport.revision.valid` | Ревизия черновика не меньше 1. | Оптимистическая конкуренция и целостность. |
| `passport.languages.default_supported` | Default language есть в реестре локалей. | Сейчас EN/SV. Данные ABB на немецком требуют отдельной обработки языка. |
| `passport.languages.enabled_unsupported` | Все включённые языки поддерживаются. | Техническая совместимость; не подтверждение языка продажи. |
| `dpp.identity.name.present` | Есть публичное имя или имя каталога. | Тот же fallback, что у catalog.product.name.present. |
| `dpp.identity.description.present` | Есть описание в default language с предусмотренным fallback. | Содержательный минимум pilot; не отдельное универсальное требование длинного описания из закона. |
| `dpp.manufacturer.name.present` | Есть имя производителя в паспорте или каталоге. | Не проверяет юридическое лицо и полномочия. |
| `dpp.manufacturer.contact.present` | Есть email/website производителя ИЛИ ответственного оператора. | Контакт другой роли может закрыть проверку производителя. Почтовый адрес не проверяется. |
| `dpp.safety.reviewed` | Есть хотя бы одно текстовое поле/элемент списка safety. | P1: проверяющий, дата, доказательство и решение отсутствуют. В опыте прошёл текст с явным предупреждением о незавершённой проверке. |
| `dpp.recycling.instructions.present` | Есть recycling или disposal instructions. | Ссылка/текст закрывает правило; применимость к модели и полнота требований не подтверждаются. |
| `dpp.materials.valid` | Отключённый раздел — N/A; пустые материалы — Passed; иначе проверка структуры. | Нельзя трактовать как наличие состава, hazardous substances или raw-material coverage. |
| `media.primary.present` | Есть primary media или первое изображение. | Продуктовый минимум. Фото не следует объявлять универсальным нормативным блокером всех DPP. |
| `media.primary.belongs_to_product` | Выбранное media принадлежит товару и компании. | Граница целостности. При использовании media должна оставаться жёсткой. |
| `media.primary.available` | Путь есть и файл существует на catalog_media. | Доступность файла; не проверка подлинности фотографии. |
| `documents.references.valid` | Указанные ссылки документов разрешаются в tenant; пустой список проходит. | Целостность выбранных документов. Обязательная декларация не появляется автоматически. |
| `documents.current_version.valid` | У выбранных документов есть допустимая текущая версия; без ссылок проходит. | Не доказывает наличие обязательного документа. |
| `documents.file.available` | Файлы выбранных документов доступны; без ссылок проходит. | Нужное техническое условие для вложений. |
| `documents.file.metadata.valid` | У выбранных файлов PDF MIME, положительный размер и SHA256 нужного формата. | Синтаксис метаданных. Содержимое и область действия документа отдельно. |
| `certificates.metadata.complete` | У выбранных сертификатов есть issuer и issue date; без сертификатов проходит. | Не подтверждает наличие, подлинность, применимость или достаточность сертификата. |
| `certificates.not_expired` | Выбранные сертификаты не просрочены; без них проходит. | Корректная проверка известных сроков. Обязательность сертификата должна задаваться профилем. |

Правила реализованы в `app/Services/Passports/Readiness/Rules/`; точные классы и пути — в rule-catalogue.json внутри пакета доказательств.

Помимо этих 33 существуют динамические границы: устаревший publication profile блокируется `regulatory.profile.current`; обязательный, некорректный или недооценённый battery scope — `regulatory.battery.coverage`. Требования DppProfile могут повышать атрибуты и переводы до Required; недопустимая конфигурация не должна понижать проверки tenant/integrity. Поэтому 33 — число из базовой конфигурации, а не максимальное число блокеров любого товара.

`mandatory` battery scope сейчас намеренно блокирует публикацию: определение области Article 77 не реализует весь Annex XIII. Это корректная защитная граница, её нельзя убирать ради успешной демонстрации батарейного паспорта. Проверены строгий порог 2 kWh, EV/LMT без такого порога, portable вне scope Article 77, день вступления, пропущенная дата и событие remanufacturing.

## Реальные блокеры регистрации в ЕС

Все десять preparation выполнены для `acceptance` и относятся к checksum текущей публикации. Статус `blocked`, registration URI отсутствует. Эти четыре кода присутствуют в каждой записи:

| Блокер | Что нужно для рабочего продукта |
| --- | --- |
| organisation_verification_unconfirmed | Подтверждённая организация и действующий verified status в реестре ЕС |
| official_registry_schema_unconfirmed | Версионированная официальная semantic specification, data model и проверка содержимого |
| registry_transport_not_implemented | Настоящее отправление в acceptance, обработка ответов/ошибок и receipt |
| upi_conformance_and_resolution_unverified | Проверка схемы UPI и разрешения по требованиям реестра и стандартов |

HTTPS resolver и публичные файлы работоспособны. Это покрывает доступность текущей демонстрации, но не закрывает все четыре задачи. `PrepareDppRegistryRegistration` сейчас только фиксирует подготовку в базе; HTTP transport в ЕС не реализован. Во внешнем EU Registry паспорта не регистрировались.

## Справочник 71 батарейного пункта

Категорийная применимость совпала с оригиналом. Качество наполнения требует отдельного слоя: 26 candidate mappings, 43 unmapped, 2 duplicate. `BatteryPassportFieldCatalogue::assess()` для каждой строки оставляет `compliance_verified=false`. Это правильное поведение; presence не должно давать юридический Passed.

Обозначения: М — mandatory; У — conditional; О — optional when available; П — duplicate; Д — deferred in guidance на старт февраля 2027 года; Н — not displayed. Категорийный М не отменяет технических оговорок самого пункта. Например, lifetime cycles неприменим к non-cycle applications, а отдельные SOH-параметры имеют условия BMS/where possible. «Д» — состояние guidance, не автоматическая юридическая льгота на любую дату.

Строки и наименования ниже — пересказ Commission edition 2.0, © European Union 2026, CC BY 4.0. NordiPass добавляет собственные mapping и оценку достаточности.

| № | Пункт каталога | EV | LMT | Industrial | Данные NordiPass | Доступ |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Passport identifier | М | М | М | кандидат, не подтверждение | public |
| 2 | Registrant or responsible operator identity | М | М | М | кандидат, не подтверждение | public |
| 3 | Manufacturer name or trademark | М | М | М | кандидат, не подтверждение | public |
| 4 | Manufacturer postal contact | М | М | М | нет сопоставления | public |
| 5 | Manufacturer website and email | О | О | О | кандидат, не подтверждение | public |
| 6 | Battery category | М | М | М | кандидат, не подтверждение | public |
| 7 | Model and battery identification | М | М | М | кандидат, не подтверждение | public |
| 8 | Manufacturing plant location | М | М | М | кандидат, не подтверждение | public |
| 9 | Manufacturing month and year | М | М | М | кандидат, не подтверждение | public |
| 10 | Battery mass | М | М | М | нет сопоставления | public |
| 11 | Initial capacity | М | М | М | нет сопоставления | public |
| 12 | Battery chemistry | М | М | М | нет сопоставления | public |
| 13 | Hazardous substances excluding mercury, cadmium and lead | М | М | М | кандидат, не подтверждение | public |
| 14 | Suitable extinguishing agent | М | М | М | кандидат, не подтверждение | public |
| 15 | Critical raw materials above 0.1% by mass | М | М | М | кандидат, не подтверждение | public |
| 16 | Composition summary (repeated data) | П | П | П | повтор 12,13,15 | public |
| 17 | Carbon footprint declaration | Д | Д | Д | кандидат, не подтверждение | public |
| 18 | Carbon footprint label | Д | Д | Д | нет сопоставления | public |
| 19 | Responsible sourcing report | Д | Д | Д | нет сопоставления | public |
| 20 | Recovered cobalt share | Д | Д | Д | кандидат, не подтверждение | public |
| 21 | Recovered lithium share | Д | Д | Д | кандидат, не подтверждение | public |
| 22 | Recovered nickel share | Д | Д | Д | кандидат, не подтверждение | public |
| 23 | Recovered lead share | Д | Д | Д | кандидат, не подтверждение | public |
| 24 | Renewable content share | М | М | М | кандидат, не подтверждение | public |
| 25 | Rated capacity (repeated initial capacity) | П | П | П | повтор 11 | public |
| 26 | Minimum voltage and relevant temperature range | М | М | М | нет сопоставления | public |
| 27 | Nominal voltage and relevant temperature range | М | М | М | нет сопоставления | public |
| 28 | Maximum voltage and relevant temperature range | М | М | М | нет сопоставления | public |
| 29 | Initial power capability | М | М | М | нет сопоставления | public |
| 30 | Power limits and relevant temperature range | М | М | М | нет сопоставления | public |
| 31 | Expected cycle lifetime | М | М | У | нет сопоставления | public |
| 32 | Cycle lifetime reference test | М | М | У | нет сопоставления | public |
| 33 | Capacity exhaustion threshold | М | Н | Н | нет сопоставления | public |
| 34 | Non-use temperature range and reference test | М | М | М | нет сопоставления | public |
| 35 | Calendar life commercial warranty period | У | У | У | кандидат, не подтверждение | public |
| 36 | Initial round trip efficiency | М | М | У | нет сопоставления | public |
| 37 | Round trip efficiency at half cycle life | М | М | У | нет сопоставления | public |
| 38 | Initial cell and pack resistance | М | М | М | нет сопоставления | public |
| 39 | Cycle test C-rate | М | М | У | нет сопоставления | public |
| 40 | Separate collection marking | М | М | М | нет сопоставления | public |
| 41 | Cadmium or lead marking | У | У | У | нет сопоставления | public |
| 42 | EU declaration of conformity | М | М | М | кандидат, не подтверждение | public |
| 43 | Waste prevention and management information | М | М | М | кандидат, не подтверждение | public |
| 44 | Downloadable and printable usage instructions | Д | Д | Д | кандидат, не подтверждение | public |
| 45 | Cathode, anode and electrolyte composition | М | М | М | нет сопоставления | legitimate interest |
| 46 | Component part numbers | М | М | М | кандидат, не подтверждение | legitimate interest |
| 47 | Replacement spare suppliers | М | М | М | кандидат, не подтверждение | legitimate interest |
| 48 | Dismantling diagrams, sequences, fasteners, tools, risks and cell layout | М | М | М | кандидат, не подтверждение | legitimate interest |
| 49 | Safety measures | М | М | М | кандидат, не подтверждение | legitimate interest |
| 50 | Compliance test reports | М | М | М | кандидат, не подтверждение | authorities / notified bodies |
| 51 | Current rated capacity | М | М | У | нет сопоставления | restricted dynamic |
| 52 | Capacity fade | М | М | У | нет сопоставления | restricted dynamic |
| 53 | Current power | М | М | У | нет сопоставления | restricted dynamic |
| 54 | Power fade | М | М | У | нет сопоставления | restricted dynamic |
| 55 | Current internal resistance | М | М | У | нет сопоставления | restricted dynamic |
| 56 | Resistance increase | М | М | У | нет сопоставления | restricted dynamic |
| 57 | Current round trip efficiency | У | У | У | нет сопоставления | restricted dynamic |
| 58 | Efficiency fade | У | У | У | нет сопоставления | restricted dynamic |
| 59 | Expected cycle lifetime under design reference conditions | М | М | У | нет сопоставления | restricted dynamic |
| 60 | Expected calendar lifetime under design reference conditions | М | М | У | нет сопоставления | restricted dynamic |
| 61 | State of certified energy (SOCE) | М | Н | Н | нет сопоставления | restricted dynamic |
| 62 | Remaining capacity (Article 14) | Н | М | У | нет сопоставления | restricted dynamic |
| 63 | Remaining power capability (Article 14) | Н | М | У | нет сопоставления | restricted dynamic |
| 64 | Remaining round trip efficiency (Article 14) | Н | М | У | нет сопоставления | restricted dynamic |
| 65 | Self-discharge evolution (Article 14) | Н | М | У | нет сопоставления | restricted dynamic |
| 66 | Ohmic resistance (Article 14) | Н | М | У | нет сопоставления | restricted dynamic |
| 67 | Battery lifecycle status | М | М | М | нет сопоставления | restricted dynamic |
| 68 | Charge and discharge cycle count | У | У | У | нет сопоставления | restricted dynamic |
| 69 | Accidents and other adverse events | У | У | У | нет сопоставления | restricted dynamic |
| 70 | Recorded operating environment and temperature | У | У | У | нет сопоставления | restricted dynamic |
| 71 | Recorded state of charge | У | У | У | нет сопоставления | restricted dynamic |

Особенно существенные семантические пробелы: URL не подтверждает стандартизованный UPI; display name не подтверждает оператора; страна не идентифицирует завод; энергия kWh для порога не заменяет initial capacity Ah; generic materials не доказывают hazardous substances/critical materials/renewable content; любой PDF не доказывает EU declaration; гарантия товара не обязательно calendar-life warranty. Initial capacity и динамическую capacity нельзя дублировать одним значением.

Restricted и dynamic данные требуют отдельных прав, источников, наблюдений с датой, событий и истории изменения. Public/internal visibility текущих документов не реализует полный режим Annex XIII. На старте нового изделия не надо выдумывать ещё не существующую историю эксплуатации; исходный статус и применимые performance параметры должны оцениваться отдельно. Правила доступа нужно версионировать с окончательным актом — FAQ сентября 2026 года сообщает, что детальный акт ещё разрабатывается.

## Fortnox на тестовой среде

Staging использует HTTP provider, pinned test client и ожидаемый tenant 1854146. Guard проверил tenant существующего access token: 1854146. Соединение active. Legacy-колонка external_tenant_id пустая, но guard установил реальный tenant из серверного token; это не разрешение неизвестного account.

Указанный пользователем sync завершён с нулевыми счётчиками и watermark `2026-10-08T12:00:05Z`. Диагностика тем же свежим token: без фильтра первая страница вернула 100 articles и next page 2; с текущим incremental filter вернула 0 и next page null. Поэтому нулевой sync подтверждает отсутствие изменений по этому фильтру, а не пустой Fortnox catalogue. Диагностика сделала два read-only GET; полный проход страниц, принудительный full sync и token renewal в этом испытании не выполнялись.

Для интерфейса стоит показывать mode incremental/full, watermark, страницы и «Нет изменений с …»; для заполнения существующего каталога нужен явный full sync с preview результата. Полный sync не должен сбрасывать watermark без подтверждения успешного прохода. Пользователь уже подтвердил успешное соединение после доставки тестовой конфигурации.

## Воспроизведённые проблемы и порядок исправления

| Приоритет | Наблюдение | Предлагаемое изменение |
| --- | --- | --- |
| P1 | safety reviewed проходит по любому непустому content; настоящий review отсутствует | Разделить safety content present и reviewed evidence: reviewer, date, decision, revision и область проверки |
| P1 | readiness 87 и 0 blockers совместимы с sector_assessment_required и четырьмя registry blockers | Показывать три самостоятельных статуса; для режима нормативного DPP требовать завершённый scope и применимый профиль |
| P1 | Manufacturer check принимает контакт оператора; postal contact и organisation proof не покрыты | Разделить роли manufacturer/importer/responsible operator и проверять каждую по применимости |
| P1 | Пустые документы/сертификаты/состав проходят проверки valid | В отраслевых профилях требовать наличие нужного evidence, покрытие модели, допустимое approval и действующий срок |
| P1 для battery launch | 43 unmapped, 26 candidate only, restricted access и telemetry отсутствуют как полный domain | Реализовать отдельные typed battery fields, units, applicability, instance identity, lineage, access и observations |
| P2 | Справочник содержит conditional, но не все технические predicates выражены в структурных данных | Добавить applicable / not applicable / unknown с обоснованием и отдельными facts для BMS, cycle applications, chemistry, warranty |
| P2 | ABB decimal string `0,0040` даёт invalid_attribute_value при decimal attribute mapping | Добавить явную source locale/decimal normalization на preview; показать исходное и преобразованное значение. Это воспроизведено отдельно, масса в нынешних десяти паспортах не обходила ошибку |
| P2 | Внешний ZIP не поддержан | Ясный экран распаковки; затем безопасный пакетный importer с manifest и ограничениями архива |
| P2 | Есть только EN/SV, оригинальный каталог DE; default-language rule проверяет ключ translations | Настройки языка по рынку, поддержка нужных локалей, полнота обязательных текстов и явное происхождение перевода |
| P2 | Два правила имени проверяют один fallback; три media ошибки возникают из одной отсутствующей фотографии | Группировать причины для пользователя, пересмотреть score и зависимые сообщения; сохранить проверки целостности выбранных assets |
| P2 | Fortnox 0 fetched воспринимается как пустой каталог | Показать режим/дату/страницы и отдельный full-sync preview |

## Предложение структуры продукта для согласования

Одна карточка товара должна показывать: источник и качество данных → применимость требований → необходимые доказательства → публикацию → регистрацию. Для каждого отсутствующего факта нужны владелец задачи, следующий шаг и источник требования. Unknown нельзя автоматически превращать в N/A или Passed.

Предлагаются три независимых уровня: (1) техническая готовность страницы и tenant/snapshot integrity; (2) данные конкретного отраслевого профиля; (3) регистрация в EU acceptance/production с реальным receipt. Score оставить показателем качества наполнения, без интерпретации как legal-compliance percentage.

Блокеры нужно разделять по профилям: общий pilot catalogue; battery EV/LMT/industrial с правилами готовой батареи и порогом; затем конкретные ESPR delegated-act profiles и отдельные законодательные семейства construction/toys/detergents с применимыми датами. Нельзя сделать общий «electronics legal profile» только из названия категории. Manufacturer/importer/operator, model/batch/item и языки рынка выбираются до выдачи списка обязательных полей.

Первый этап исправлений: достоверные названия проверок, три статуса и их границы, typed manufacturer roles и evidence requirements. Второй: import locale, документы/изображения и reusable mapping ABB/Fortnox. Третий: полноценная батарейная модель и интеграция EU acceptance. Эту логику приложения ещё не изменяли: согласно условию пользователя сначала подготовлены идеи и конкретные результаты проверки для согласования.

## Проверки и сохранённые доказательства

Рабочая папка: `storage/framework/passport-review-20261013/`. В ней source extraction, CSV, supplier images/PDF, cohort harness, итоговый staging JSON, HTTP receipts, PNG QR и decoder results, 71-point comparison, снимок публичной страницы и результаты теста. Предыдущее чтение исходного полного XLSX и decimal regression: `storage/framework/review-20261012/`; там 4 теста / 21 assertions passed и результат ранее выполненного набора 798 tests / 3181 assertions. Это результаты разных прогонов; они не объявляются одним полным текущим quality gate.

Windows PHP сообщает о повторной загрузке ряда расширений; итоговые функциональные тесты passed. В тесте загрузки изображения первоначально был неверно объявлен MIME в scratch harness; harness исправлен на реальный MIME без изменения приложения и без преобразования исходных байтов. QR локального теста и staging сохранены отдельно.

Изменения в этом этапе: отдельная компания, импорт/managed/photo/document/review/publication/registry-preparation records на staging, scratch acceptance scripts и этот отчёт. Бизнес-логика и frontend проекта в этом этапе не исправлялись. Ранее разрешённая доставка Fortnox config и deployment safety tooling описана отдельно; её тестовый tenant guard сохранён. Посторонние локальные изменения и удаления demo файлов не включались в результаты и не отменялись.
