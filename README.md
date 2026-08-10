# Haier hOn Smart-Home Research

Dieses Repository sammelt Arbeitsnotizen und Referenzen zur inoffiziellen Haier hOn API sowie zur Umsetzung einer Smart-Home-Integration, insbesondere fuer IP-Symcon.

## Inhalt

- `docs/HAIER_HON_API_REFERENCE.md`: API-Notizen zu Authentifizierung, Endpunkten, Geraetedaten und Sicherheitsaspekten.
- `docs/symcon/SYMCON_REFERENCE.md`: lokale Referenz fuer IP-Symcon-Modulstruktur und SDK-Konventionen.
- `docs/symcon/MODULE_CHECKLIST.md`: Checkliste fuer Aufbau, Robustheit und Pruefung eines Symcon-Moduls.

## Status

Der aktuelle Stand ist eine Dokumentations- und Planungsbasis. Eine produktive Integration sollte sorgfaeltig mit Testgeraeten validiert werden, da die hOn API nicht offiziell fuer Drittanbieter dokumentiert ist und sich ohne Vorankuendigung aendern kann.

## Sicherheit

Keine Zugangsdaten, Tokens, Cookies oder Geraete-IDs in dieses Repository committen. Steuerbefehle an Haushaltsgeraete sollten nur nach klarer Nutzeraktion oder expliziter Automationsregel ausgefuehrt werden.
