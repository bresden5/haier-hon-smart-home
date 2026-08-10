# Haier hOn Smart-Home Research

Dieses Repository sammelt Arbeitsnotizen und Referenzen zur inoffiziellen Haier hOn API sowie zur Umsetzung einer Smart-Home-Integration, insbesondere fuer IP-Symcon.

## Inhalt

- `docs/HAIER_HON_API_REFERENCE.md`: API-Notizen zu Authentifizierung, Endpunkten, Geraetedaten und Sicherheitsaspekten.
- `docs/symcon/SYMCON_REFERENCE.md`: lokale Referenz fuer IP-Symcon-Modulstruktur und SDK-Konventionen.
- `docs/symcon/MODULE_CHECKLIST.md`: Checkliste fuer Aufbau, Robustheit und Pruefung eines Symcon-Moduls.

## Status

Dieses Repository enthaelt jetzt eine erste funktionsfaehige IP-Symcon-Bibliothek:

- `HaierhOnAccount`: Splitter fuer Login, Token-Refresh, Geraeteliste und authentifizierte API-Aufrufe.
- `HaierhOnDevice`: Device-Modul fuer ein hOn-Geraet mit Kontext-Polling, Statusvariablen und Basisbefehlen.

Eine produktive Integration sollte sorgfaeltig mit Testgeraeten validiert werden, da die hOn API nicht offiziell fuer Drittanbieter dokumentiert ist und sich ohne Vorankuendigung aendern kann.

## IP-Symcon Einrichtung

1. Repository als Modulbibliothek in IP-Symcon einbinden.
2. Eine Instanz `Haier hOn Account` anlegen.
3. E-Mail und Passwort oder einen vorhandenen Refresh-Token hinterlegen.
4. `Login / Tokens erneuern` und danach `Geraete laden` ausfuehren.
5. Pro Appliance eine `Haier hOn Device` Instanz anlegen und `macAddress`, `applianceType`, `applianceModelId`, `code` sowie optional Firmware-/Serienfelder aus dem Appliance-JSON uebernehmen.
6. Im Device `Status aktualisieren` und `Befehlsdefinitionen aktualisieren` testen.

Hinweis: Der Login nutzt den aus pyhOn bekannten hOn/Salesforce-OAuth-Ablauf mit Cookies und Redirects. Die PHP-Umgebung von IP-Symcon muss dafuer cURL bereitstellen.

## Sicherheit

Keine Zugangsdaten, Tokens, Cookies oder Geraete-IDs in dieses Repository committen. Steuerbefehle an Haushaltsgeraete sollten nur nach klarer Nutzeraktion oder expliziter Automationsregel ausgefuehrt werden.
