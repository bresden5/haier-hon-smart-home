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
5. Pro Appliance eine `Haier hOn Device` Instanz anlegen und `macAddress`, `applianceType` oder `applianceTypeName` (z. B. `WM`), `applianceModelId`, `code` sowie optional `eepromId`/`firmwareId`, `fwVersion` und `series` aus dem Appliance-JSON uebernehmen.
6. Im Device `Status aktualisieren` und `Befehlsdefinitionen aktualisieren` testen.

Der primaere Login nutzt inzwischen den aus der aktuellen Home-Assistant-Integration `gvigroux/hon` abgeleiteten CIAM-PKCE-Ablauf ueber `https://api-iot.he.services/ciam/authorize` und `/ciam/token`. Der alte hOn/Salesforce-OAuth-Ablauf bleibt nur als Fallback erhalten. Die PHP-Umgebung von IP-Symcon muss dafuer cURL bereitstellen.

Konten, die in der hOn-App ueber Google, Apple oder Facebook angelegt wurden, muessen nach Herstellerhinweis auch wieder ueber diese Methode angemeldet werden. Das Modul unterstuetzt aktuell den hOn E-Mail/Passwort-Login und vorhandene Refresh-Tokens, aber keinen interaktiven Google-/Social-/Captcha-Login.

Auch wenn die App E-Mail- und Kennwortfelder anzeigt, kann der Server nach dem Absenden fuer einzelne Konten in einen Google-Identity- oder Captcha-Flow wechseln. In diesem Fall ist ein automatischer Hintergrund-Login nicht moeglich. Als Ausweg kann in der Account-Instanz eine vollstaendige `hon://mobilesdk/detect/oauth/done#...` OAuth-Callback-URL importiert werden, falls sie aus einer interaktiven Anmeldung verfuegbar ist.

## Sicherheit

Keine Zugangsdaten, Tokens, Cookies oder Geraete-IDs in dieses Repository committen. Steuerbefehle an Haushaltsgeraete sollten nur nach klarer Nutzeraktion oder expliziter Automationsregel ausgefuehrt werden.
