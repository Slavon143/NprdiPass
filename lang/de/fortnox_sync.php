<?php

return [
    'mode' => 'Synchronisierungsmodus', 'incremental' => 'Änderungen seit dem Datenstand', 'full' => 'Vollständige Katalogabfrage', 'unknown' => 'Nicht erfasst',
    'pages' => 'Abgerufene Seiten', 'watermark' => 'Datenstand', 'filter' => 'Änderungen seit', 'unfiltered' => 'Alle sichtbaren Artikel',
    'no_changes' => 'Keine Änderungen seit :date. Das bedeutet nicht, dass der Fortnox-Katalog leer ist.',
    'empty_full' => 'Die abgeschlossene vollständige Abfrage hat keine sichtbaren Artikel geliefert.',
    'empty_unknown' => 'Es wurden keine Artikel geliefert. Der Filter dieser früheren Abfrage wurde nicht erfasst.',
    'partial' => 'Die Abfrage ist unvollständig. Setzen Sie sie ab Seite :page fort; der Datenstand wurde nicht vorgerückt.',
    'failed' => 'Die Synchronisierung ist fehlgeschlagen. Der Datenstand wurde nicht vorgerückt; prüfen Sie den Fehler und versuchen Sie es erneut.',
    'running' => 'Die Synchronisierung läuft. Der Datenstand wird erst nach Abschluss der Abfrage vorgerückt.',
    'completed' => 'Fortnox-Synchronisierung abgeschlossen: :fetched abgerufen, :created erstellt, :updated aktualisiert, :skipped übersprungen, :conflicts Konflikte.',
    'preview_button' => 'Vorschau des gesamten Katalogs', 'preview_title' => 'Vorschau des gesamten Katalogs',
    'preview_intro' => 'Diese Abfrage liest alle sichtbaren Fortnox-Artikel. Sie ändert keine Katalogprodukte, Zuordnungen oder den Datenstand.',
    'total' => 'Sichtbare Artikel', 'mapped' => 'Bereits zugeordnete Artikel', 'unmapped' => 'Artikel ohne Zuordnung',
    'preview_note' => 'Diese Zahlen dienen der Vorschau und garantieren keine Anzahl erstellter oder aktualisierter Produkte. Der Katalog kann sich vor dem Import ändern. Bestehende Zuordnungen, lokale Anpassungen und Konfliktprüfungen bleiben erhalten.',
    'confirm' => 'Gesamten Katalog importieren', 'cancel' => 'Zurück zur Integration',
    'test_only' => 'Vorschau und Import des gesamten Katalogs sind derzeit nur in der Testumgebung verfügbar.',
];
