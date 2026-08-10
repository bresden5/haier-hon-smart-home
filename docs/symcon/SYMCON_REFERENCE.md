# Symcon Modul-Referenz fuer dieses Projekt

Stand: 2026-08-10
Primaerquelle: https://www.symcon.de/de/service/dokumentation/

Diese Datei ist die lokale Arbeitsbibliothek fuer Anpassungen an IP-Symcon-Modulen in diesem Projekt. Sie fasst die offiziellen Vorgaben zusammen und verlinkt auf die Originaldokumentation. Sie ist bewusst keine Kopie der Symcon-Dokumentation.

## Quellenindex

- Einstieg Dokumentation: https://www.symcon.de/de/service/dokumentation/
- Entwicklerbereich: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/
- SDK PHP: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/
- Bibliotheken / library.json: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/bibliotheken/
- Struktur: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/struktur/
- Module / module.php / module.json: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/
- Konfigurationsformulare / form.json: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/konfigurationsformulare/
- Lokalisierungen / locale.json: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/lokalisierungen/
- Datenverwaltung: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/datenverwaltung/
- Datenfluss: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/datenfluss/
- Aktionen: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/aktionen/
- Limitationen: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/limitationen/

## Bibliotheksstruktur

Eine Symcon-Bibliothek wird ueber `library.json` im Hauptverzeichnis eingebunden. Modulordner enthalten jeweils `module.php` und `module.json`. Optional sind `form.json` und `locale.json`.

Erlaubte projektweite Sonderordner ohne `module.json`:

- `libs/` ab Symcon 4.2
- `docs/` ab Symcon 4.2
- `imgs/` ab Symcon 4.2
- `tests/` ab Symcon 4.4
- `actions/` ab Symcon 6.0
- Punktordner wie `.github` oder `.style` werden ignoriert.

Der Modulordner sollte denselben Namen wie die Klasse in `module.php` tragen.

## library.json

Pflicht- und Kernfelder:

- `id`: GUID im Format `{12345678-90AB-CDEF-1234-567890ABCDEF}`, Grossbuchstaben, Bindestriche und geschweifte Klammern.
- `author`: Autor der Bibliothek.
- `name`: Bibliotheksname. Erlaubt sind Buchstaben, Zahlen, Leerzeichen und Unterstrich; kein fuehrendes oder abschliessendes Leerzeichen/Unterstrich.
- `url`: Homepage-URL mit `http://` oder `https://`, alternativ leer.
- `compatibility`: optional ab 4.3, z. B. Mindestversion und/oder Datum.
- `version`: frei definierter String, empfohlen z. B. `1.0`.
- `build`: Integer.
- `date`: Unix-Zeitstempel.

## module.json

Wichtige Felder:

- `id`: eindeutige Modul-GUID.
- `name`: Modulname; muss zur Klasse passen.
- `type`: `0` Kern, `1` I/O, `2` Splitter, `3` Device, `4` Konfigurator, `5` Discovery.
- `vendor`: Hersteller/Menuepunkt beim Hinzufuegen einer Instanz; leer landet unter Sonstige.
- `aliases`: alternative Geraetenamen.
- `url`: Dokumentations-URL, leer erlaubt.
- `parentRequirements`: Datenfluss-GUIDs kompatibler Parents.
- `childRequirements`: Datenfluss-GUIDs kompatibler Children.
- `implemented`: Datenfluss-GUIDs, die in `ReceiveData` oder `ForwardData` verarbeitet werden.
- `prefix`: Funktionspraefix fuer oeffentliche Modulbefehle; nur Zahlen und Buchstaben.

## module.php

Neue Module sollen `IPSModuleStrict` verwenden. `IPSModule` bleibt verfuegbar, ist fuer neue Module aber nicht die bevorzugte Basis.

Regeln:

- Klassenname entspricht `module.json.name`; Leerzeichen werden im Klassennamen entfernt.
- Oeffentliche Funktionen brauchen bei `IPSModuleStrict` vollstaendige Type Hints.
- Funktionsnamen duerfen nur `a..z`, `A..Z`, `0..9` enthalten.
- `$InstanceID` darf nicht als Parametername verwendet werden.
- `Create()` und `ApplyChanges()` rufen immer zuerst bzw. passend `parent::Create()` und `parent::ApplyChanges()` auf.
- Bei `IPSModuleStrict` schreibt das Modul eigene Statusvariablen ueber `$this->SetValue(...)`.
- `RegisterVariable*` liefert bei `IPSModuleStrict` boolean zurueck, nicht die Variablen-ID.

Minimalmuster:

```php
class BeispielModul extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }
}
```

## Datenverwaltung

Symcon unterscheidet vier Datenarten:

- Eigenschaften: persistent, vom Benutzer konfiguriert, erst nach Uebernehmen gespeichert. API: `RegisterProperty*`, `ReadProperty*`.
- Attribute: persistent, nur vom Modul verwaltet, sofort gespeichert. API: `RegisterAttribute*`, `ReadAttribute*`, `WriteAttribute*`.
- Buffer: nicht persistent, fuer interne Laufzeitdaten oder fragmentierte Eingangsdatensaetze. API: `SetBuffer`, `GetBuffer`, `GetBufferList`.
- Statusvariablen: persistent, sichtbar im Objektbaum, fuer Visualisierung und Automatisierung. API: `RegisterVariable*`, `MaintainVariable`, `SetValue`, `GetValue`, `EnableAction`, `DisableAction`, `MaintainAction`, `RequestAction`.

Leitlinie:

- Benutzerkonfiguration gehoert in Properties und `form.json`.
- Tokens, Sessiondaten und vom Modul verwaltete dauerhafte Zustaende gehoeren in Attribute.
- Temporäre Parser- oder Transportdaten gehoeren in Buffer.
- Messwerte, Schaltzustaende und UI-relevante Werte gehoeren in Statusvariablen.

## Konfigurationsformulare

`form.json` ist in Bereiche gegliedert:

- `elements`: Felder, die Instanzeigenschaften setzen. Der `name` eines Feldes entspricht in der Regel der Property.
- `actions`: Test- und Aktionsbereich; aendert keine Properties. Tests sollen erst nach Uebernehmen der Konfiguration sinnvoll laufen.
- `status`: Statusmeldungen, keine Formularfelder.

Hauefige Feldtypen:

- Eingaben: `ValidationTextBox`, `PasswordTextBox`, `NumberSpinner`, `CheckBox`, `HorizontalSlider`.
- Auswahl: `Select`, `SelectInstance`, `SelectVariable`, `SelectObject`, `SelectCategory`, `SelectProfile`, `SelectFile`.
- Layout/Anzeige: `Label`, `Image`, `RowLayout`, `ColumnLayout`, `ExpansionPanel`, `PopupButton`, `PopupAlert`.
- Konfiguratoren: `Configurator`, `Tree`, `List`.
- Neuere Spezialfelder je nach Symcon-Version: z. B. `QrCode`, `ProgressBar`, `ScriptEditor`, `TestCenter`.

Bei dynamischen Formularaenderungen per `UpdateFormField` muessen komplexe Werte wie `columns`, `sort` oder `values` JSON-codiert werden.

## Lokalisierung

`locale.json` uebersetzt `caption` und `label` aus der Konfigurationsseite. Sprachschluessel koennen allgemein oder regional sein, z. B. `de`, `de_DE`, `de_AT`, `de_CH`.

Empfehlung:

- Formulartexte in einer Basissprache konsistent halten.
- Uebersetzbare Texte in `locale.json` pflegen.
- Keine technischen Identifikatoren uebersetzen.

## Datenfluss

Typischer Aufbau: Device <-> Splitter <-> I/O.

Richtungen:

- Parent zu Child: Parent nutzt `SendDataToChildren`; Child verarbeitet in `ReceiveData`.
- Child zu Parent: Child nutzt `SendDataToParent`; Parent verarbeitet in `ForwardData`.

Einrichtung:

- Datenpakete sind JSON-Strings.
- `DataID` ist die GUID des Datenpakettyps.
- Sender traegt passende GUID in `parentRequirements` oder `childRequirements` ein.
- Empfaenger traegt die GUID in `implemented` ein.
- Bei `IPSModuleStrict` erstellt die Verwaltungskonsole kompatible Datenfluss-Verbindungen automatisch. Bei Spezialfaellen kann `GetCompatibleParents()` genutzt werden.

Bekannte I/O-Modul-GUIDs:

- Client Socket: `{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}`
- HTTP Client: `{4CB91589-CE01-4700-906F-26320EFCF6C4}`
- Serial Port: `{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}`
- Server Socket: `{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}`
- UDP Socket: `{82347F20-F541-41E1-AC5B-A636FD3AE2D8}`
- Virtual I/O: `{6179ED6A-FC31-413C-BB8E-1204150CF376}`
- WebSocket Client: `{D68FD31F-0E90-7019-F16C-1949BD3079EF}`

Bekannte Datenpaket-GUIDs aus der Doku:

- Simpel RX: `{018EF6B5-AB94-40C6-AA53-46943E824ACF}`
- Simpel TX: `{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}`
- Erweitert Socket RX: `{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}`
- Erweitert Socket TX: `{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}`

Wichtig: Die Doku unterscheidet bei neuerem `IPSModuleStrict` eine HEX-kodierte Datenfluss-Kodierung gegenueber der aelteren UTF-8-Kodierung. Bei bestehenden Modulen zuerst pruefen, welche Basisklasse und welche Gegenstelle verwendet wird.

## Aktionen

Eigene Aktionen liegen ab Symcon 6.0 als JSON-Dateien im Ordner `actions/`.

Kernfelder:

- `id`: eindeutige GUID der Aktion.
- `caption`: sichtbare Bezeichnung.
- `form`: Array, String oder PHP-Code, der ein Formularobjekt liefert.
- `format`: optionale Darstellung, inklusive Symcon-spezifischer Typen wie `profile`, `valueFormatted` und `object`.

## Grenzen und Performance

Beim Design beachten:

- Maximal 50.000 Objekte; je nach Lizenz niedriger.
- String-Variable: Fehler ab 1024 kB; aus Stabilitaetsgruenden Daten ueber 8 kB besser auslagern.
- RegisterVariable- und Cutter-Puffer: Warnung ab 8 kB, nur letzte 64 kB bleiben erhalten.
- Buffer: Softlimit 256 kB, Hardlimit 1024 kB.
- PHP-Threads: Standard 50, Minimum 25; sehr hohe Werte verbrauchen unnoetig RAM.
- Speicher pro PHP-Thread: Standard 32 MB, maximal 64 MB.
- `AC_*` Abfragen liefern maximal 10.000 Zeilen.
- Variablenprofile: maximal 128 Assoziationen ab Symcon 5.0.

## Arbeitsregeln fuer dieses Projekt

Bei jeder Modul-Anpassung:

1. `library.json`, `module.json`, `module.php`, `form.json` und `locale.json` gegen diese Referenz pruefen.
2. Bestehende Modulstruktur und Prefixe beibehalten.
3. Neue oeffentliche Funktionen nur mit Type Hints und Symcon-kompatiblen Namen.
4. Properties, Attribute, Buffer und Statusvariablen bewusst trennen.
5. Datenfluss-GUIDs nur dann aendern, wenn Parent/Child-Kompatibilitaet verstanden ist.
6. Formulare so bauen, dass benannte `elements` mit registrierten Properties zusammenpassen.
7. Bei grossen Strings, Puffern oder API-Antworten die Symcon-Limits einplanen.
8. Neue Symcon-Funktionen mit Mindestversion in `compatibility` absichern.

