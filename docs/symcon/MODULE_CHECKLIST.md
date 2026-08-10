# Symcon Modul-Checkliste

Diese Checkliste verwende ich vor und nach Aenderungen an deinem Symcon-Modul.

## Struktur

- `library.json` liegt im Hauptverzeichnis.
- Jeder Modulordner enthaelt `module.php` und `module.json`.
- Optionale Dateien sind passend platziert: `form.json`, `locale.json`.
- Sonderordner ohne `module.json` sind nur bekannte Ordner wie `libs`, `docs`, `imgs`, `tests`, `actions` oder Punktordner.

## Manifestdateien

- Alle GUIDs haben Format `{XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}` mit Grossbuchstaben.
- `library.json.name` und `module.json.name` enthalten keine ungueltigen Randzeichen.
- `module.json.type` passt zur Rolle: I/O, Splitter, Device, Konfigurator oder Discovery.
- `prefix` enthaelt nur Buchstaben und Zahlen.
- `parentRequirements`, `childRequirements` und `implemented` passen zum Datenfluss.
- `compatibility` deckt genutzte Symcon-Versionen ab.

## PHP-Modul

- Neue Module verwenden `IPSModuleStrict`.
- Klassennamen passen zu `module.json.name`.
- `Create()` ruft `parent::Create()`.
- `ApplyChanges()` ruft `parent::ApplyChanges()`.
- Oeffentliche Methoden haben Type Hints und Return Types.
- Keine oeffentliche Methode nutzt ungueltige Zeichen im Namen.
- Kein Parameter heisst `$InstanceID`.
- Statusvariablen werden intern mit `$this->SetValue(...)` geschrieben.

## Datenmodell

- Benutzerkonfiguration ist als Property registriert und in `form.json` abgebildet.
- Modulinterne persistente Daten sind Attribute.
- Laufzeit-/Parserdaten sind Buffer und bleiben unter den bekannten Limits.
- Sichtbare Werte sind Statusvariablen mit passenden Profilen und Actions.
- `RequestAction()` validiert Eingaben und leitet Steuerbefehle kontrolliert weiter.

## Formular und Lokalisierung

- `elements` setzen Properties.
- `actions` dienen nur Test- oder Hilfsaktionen.
- `status` enthaelt nur Statusmeldungen.
- Dynamische Formularfelder codieren komplexe Werte fuer `UpdateFormField` als JSON.
- `locale.json` enthaelt sichtbare `caption`- und `label`-Texte.

## Datenfluss

- `SendDataToParent` und `SendDataToChildren` nutzen passende `DataID`.
- `ReceiveData` und `ForwardData` pruefen und verarbeiten nur unterstuetzte Datenpakete.
- Kodierung passt zur Basisklasse und Gegenstelle.
- Bei `IPSModuleStrict` wird automatische Parent-Kompatibilitaet beruecksichtigt.

## Robustheit

- API-Fehler, Timeouts und Authentifizierungsfehler setzen nachvollziehbare Statusmeldungen.
- Grosse Antworten werden nicht ungefiltert in String-Variablen geschrieben.
- Debug-Ausgaben enthalten keine geheimen Tokens oder Passwoerter.
- Tests oder zumindest Syntaxpruefungen laufen nach Aenderungen, soweit lokal moeglich.

