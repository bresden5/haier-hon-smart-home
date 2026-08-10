# Haier hOn API-Referenz fuer Smart-Home-Integrationen

Stand: 2026-08-10

Diese Datei beschreibt den aktuellen Wissensstand zur inoffiziellen Haier-hOn-Cloud-API, soweit er aus oeffentlich verfuegbaren Reverse-Engineering-Projekten ableitbar ist. Sie ist als Arbeitsgrundlage gedacht, um eine vergleichbare Integration fuer eine andere Smart-Home-Software zu bauen, z. B. IP-Symcon, ioBroker, openHAB oder eine eigene Middleware.

## Quellenindex

- pyhOn Projekt: https://git.xicon.eu/xiconfjs/pyhOn
- pyhOn PyPI: https://pypi.org/project/pyhOn/
- Home Assistant Integration `Andre0512/hon`: https://github.com/Andre0512/hon
- Alternative Home Assistant Integration `gvigroux/hon`: https://github.com/gvigroux/hon
- hOn FAQ: https://hon-smarthome.com/faq/

## Status und Einordnung

Haier veroeffentlicht nach aktuellem Stand keine allgemein dokumentierte, offizielle hOn-API fuer Drittanbieter. Die nutzbaren Informationen stammen aus inoffiziellen Projekten, vor allem `pyhOn` und Home-Assistant-Integrationen.

Wichtige Konsequenzen:

- Die API kann ohne Vorankuendigung geaendert oder gesperrt werden.
- Authentifizierung, Header, Endpunkte und Payloads sind nicht vertraglich stabil.
- Nutzung kann gegen Nutzungsbedingungen des Herstellers verstossen.
- Tokens, Passwoerter und Geraetedaten muessen besonders sorgfaeltig behandelt werden.
- Steuerbefehle an Haushaltsgeraete duerfen nur nach bewusster Nutzeraktion oder klarer Automationsregel gesendet werden.

## Zielarchitektur

Eine robuste Smart-Home-Integration sollte die hOn-Anbindung in mehrere Schichten trennen:

- Account/Auth: Login, Token-Erneuerung, Session-Header.
- Cloud-Client: HTTP-Aufrufe gegen die hOn-API.
- Geraetemodell: Appliance-Liste, Metadaten, Attribute, Kommandos.
- Plattformadapter: Statusvariablen, UI, Aktionen, Automationen der Zielsoftware.
- Sicherheitslogik: Plausibilitaet, Remote-Control-Status, Fehlerbehandlung, Rate-Limits.

Empfohlener Datenfluss:

1. Benutzer hinterlegt hOn E-Mail und Passwort oder einen wiederverwendbaren Refresh-Token.
2. Integration authentifiziert sich bei hOn.
3. Integration liest die verbundenen Geraete.
4. Integration laedt pro Geraet Kommandos, Attribute und optional Statistiken.
5. Integration bildet relevante Werte auf Statusobjekte der Zielplattform ab.
6. Schreibzugriffe laufen nur ueber validierte Kommandos mit erlaubten Parametern.

## Bekannte Hosts und Konstanten

Aus `pyhOn` abgeleitete Werte:

- Auth-Basis: `https://account2.hon-smarthome.com`
- API-Basis: `https://api-iot.he.services`
- AWS IoT Endpoint: `a30f6tqw0oh1x0-ats.iot.eu-west-1.amazonaws.com`
- AWS Authorizer: `candy-iot-authorizer`
- App-Identifier: `hon`
- Plattform: `android`
- Content-Type: `application/json`

In `pyhOn` sind ausserdem App-Version, User-Agent, Client-ID und ein API-Key fuer anonyme Konfigurationsaufrufe hinterlegt. Diese Werte sollten nicht blind hart codiert werden, sondern zentral konfigurierbar sein, da sie sich mit App-Versionen aendern koennen.

## Authentifizierung

Die Authentifizierung ist der empfindlichste Teil der Integration. `pyhOn` bildet im Kern den mobilen OAuth-/Salesforce-Login der hOn-App nach.

Grobablauf:

1. OAuth-Authorize-Seite oeffnen:
   `GET /services/oauth2/authorize/expid_Login`
2. Login-Seite und Redirects auswerten.
3. Benutzername und Passwort an den Login-Controller senden.
4. Redirect mit `access_token`, `refresh_token` und `id_token` auswerten.
5. Gegen hOn-API anmelden:
   `POST https://api-iot.he.services/auth/v1/login`
6. Aus der Antwort den `cognito-token` uebernehmen.
7. Bei API-Aufrufen `id-token` und `cognito-token` als Header mitsenden.

Wichtige Header fuer authentifizierte API-Aufrufe:

- `Content-Type: application/json`
- `user-agent: ...`
- `id-token: ...`
- `cognito-token: ...`

Token-Erneuerung:

- Der OAuth-Refresh laeuft gegen `POST https://account2.hon-smarthome.com/services/oauth2/token`.
- Parameter: `client_id`, `refresh_token`, `grant_type=refresh_token`.
- Danach muss erneut `POST /auth/v1/login` gegen die API-Basis laufen, um den Cognito-Token zu aktualisieren.

Empfehlung fuer neue Integrationen:

- Passwoerter nie im Klartext loggen.
- Tokens als geschuetzte Attribute/Secrets speichern, nicht in sichtbaren Variablen.
- Bei HTTP 401/403 zuerst Refresh versuchen, danach einmal neu authentifizieren.
- Danach Fehler sichtbar melden und weitere Schreibbefehle blockieren.

## HTTP-Endpunkte

Die folgenden Endpunkte sind aus `pyhOn` bekannt.

### Geraete laden

`GET https://api-iot.he.services/commands/v1/appliance`

Zweck:

- Liste aller mit dem hOn-Account verbundenen Geraete laden.

Erwartete Antwortstruktur:

```json
{
  "payload": {
    "appliances": []
  }
}
```

Wichtige Felder pro Geraet:

- `macAddress`
- `applianceType`
- `applianceModelId`
- `nickName`
- `code`
- `eepromId`
- `fwVersion`
- `series`

### Befehle und Parameter laden

`GET https://api-iot.he.services/commands/v1/retrieve`

Typische Query-Parameter:

- `applianceType`
- `applianceModelId`
- `macAddress`
- `os`
- `appVersion`
- `code`
- `firmwareId`
- `fwVersion`
- `series`

Zweck:

- Verfuegbare Kommandos je Geraet laden.
- Erlaubte Parameter, Enum-Werte, Range-Minimum/-Maximum und Schrittweiten ermitteln.

### Kontext/Attribute laden

`GET https://api-iot.he.services/commands/v1/context`

Typische Query-Parameter:

- `macAddress`
- `applianceType`
- `category=CYCLE`

Zweck:

- Aktuelle Laufzeitdaten und Geraeteattribute lesen.

### Geraetemodell laden

`GET https://api-iot.he.services/commands/v1/appliance-model`

Typische Query-Parameter:

- `code`
- `macAddress`

Zweck:

- Modellinformationen und technische Metadaten lesen.

### Letzte Aktivitaet laden

`GET https://api-iot.he.services/commands/v1/retrieve-last-activity`

Typische Query-Parameter:

- `macAddress`

Zweck:

- Letzte Aktivitaet oder zuletzt gesetzte Attribute lesen.

### Statistiken laden

`GET https://api-iot.he.services/commands/v1/statistics`

Typische Query-Parameter:

- `macAddress`
- `applianceType`

Zweck:

- Verbrauchs- und Nutzungsstatistiken lesen, soweit vom Geraet bereitgestellt.

### Wartungsdaten laden

`GET https://api-iot.he.services/commands/v1/maintenance-cycle`

Typische Query-Parameter:

- `macAddress`

Zweck:

- Wartungszyklen und Pflegeinformationen lesen, soweit verfuegbar.

### Kommandohistorie laden

`GET https://api-iot.he.services/commands/v1/appliance/{macAddress}/history`

Zweck:

- Historie gesendeter Kommandos laden.

### Favoriten laden

`GET https://api-iot.he.services/commands/v1/appliance/{macAddress}/favourite`

Zweck:

- In der App definierte Favoritenprogramme lesen.

### Kommando senden

`POST https://api-iot.he.services/commands/v1/send`

Zweck:

- Steuerbefehl an ein Geraet senden.

Typischer Payload:

```json
{
  "macAddress": "AA:BB:CC:DD:EE:FF",
  "timestamp": "2026-08-10T12:00:00.000Z",
  "commandName": "startProgram",
  "transactionId": "AA:BB:CC:DD:EE:FF_2026-08-10T12:00:00.000Z",
  "applianceOptions": {},
  "device": {},
  "attributes": {
    "channel": "mobileApp",
    "origin": "standardProgram",
    "energyLabel": "0"
  },
  "ancillaryParameters": {},
  "parameters": {},
  "applianceType": "WM",
  "programName": "COTTON"
}
```

Eine erfolgreiche Antwort enthaelt nach `pyhOn` im Payload `resultCode` gleich `"0"`.

## Geraetetypen

Die Home-Assistant-Integration nennt aktuell u. a. diese hOn-Geraeteklassen:

- `WM`: Washing Machine / Waschmaschine
- `WD`: Washer Dryer / Waschtrockner
- `TD`: Tumble Dryer / Waeschetrockner
- `DW`: Dish Washer / Geschirrspueler
- `OV`: Oven / Backofen
- `AC`: Air Conditioner / Klimageraet
- Fridge / Kuehlschrank
- Hob / Kochfeld
- Hood / Dunstabzug
- Wine Cellar / Weinschrank
- Air Purifier / Luftreiniger

Die exakten `applianceType`-Codes koennen je nach Geraet variieren und sollten aus der Appliance-Liste gelesen statt geraten werden.

## Waschmaschinen: relevante Datenpunkte

Typische Steuerbefehle:

- `startProgram`
- `stopProgram`
- `pauseProgram`
- `resumeProgram`

Typische Konfigurationsparameter fuer `startProgram`:

- `program`
- `spinSpeed`
- `temp`
- `delayTime`
- `delayStatus`
- `dirtyLevel`
- `acquaplus`
- `prewash`
- `rinseIterations`
- `extraRinse1`
- `extraRinse2`
- `extraRinse3`
- `hygiene`
- `steamLevel`
- `autoDetergentStatus`
- `autoSoftenerStatus`
- `waterHard`
- `permanentPressStatus`

Typische Sensor-/Statuswerte:

- `machMode`: Maschinenstatus
- `programName`: aktives Programm
- `prPhase`: Programmphase
- `remainingTimeMM`: Restlaufzeit in Minuten
- `doorStatus`: Tuerstatus
- `doorLockStatus`: Tuerverriegelung
- `spinSpeed`: Schleuderdrehzahl
- `temp`: aktuelle Temperatur
- `delayTime`: Startverzoegerung
- `currentWaterUsed`
- `currentElectricityUsed`
- `totalWaterUsed`
- `totalElectricityUsed`
- `totalWashCycle`
- `errors`
- `attributes.lastConnEvent.category`: haeufig als Remote-/Verbindungsstatus genutzt

Nicht jedes Modell liefert alle Werte. Die Integration muss deshalb dynamisch anhand der geladenen Kommandos und Attribute arbeiten.

## Schreibzugriff sicher umsetzen

Vor jedem Steuerbefehl:

1. Pruefen, ob das Geraet online oder remote steuerbar ist.
2. Pruefen, ob der gewuenschte Befehl in `commands` vorhanden ist.
3. Nur Parameter senden, die fuer diesen Befehl bekannt sind.
4. Enum-Werte nur aus der API-Liste erlauben.
5. Range-Werte gegen `min`, `max` und `step` validieren.
6. Bei `startProgram` den Programmnamen passend setzen, falls vom Modell verlangt.
7. Ergebnis auswerten und Fehler sichtbar machen.

Empfehlung:

- `startProgram` nicht automatisch nach Neustart oder Reconnect ausloesen.
- Kritische Befehle wie Start/Stop als explizite Aktion modellieren.
- Statuswerte nach einem Schreibbefehl zeitnah neu laden.
- Rate-Limits konservativ halten, z. B. Polling nicht sekundenweise.

## Datenmodell fuer eine Zielplattform

### Account-Instanz

Speichert:

- E-Mail
- Passwort oder Refresh-Token
- aktuelle Tokens
- Token-Ablaufzeit
- letzte erfolgreiche Anmeldung

Aufgaben:

- Login und Refresh
- HTTP-Client bereitstellen
- globale Fehler melden

### Geraete-Discovery

Aufgaben:

- `load_appliances` ausfuehren
- Geraete anhand `macAddress` eindeutig identifizieren
- passende Device-Instanzen anlegen oder aktualisieren

### Geraete-Instanz

Speichert:

- `macAddress`
- `applianceType`
- `applianceModelId`
- `code`
- `firmwareId`
- zuletzt geladene Command-Definitionen
- relevante Attribute

Aufgaben:

- Attribute pollen
- Statusvariablen aktualisieren
- Aktionen validieren und senden

## Mapping fuer IP-Symcon

Eine sinnvolle Symcon-Struktur waere:

- I/O oder Splitter: hOn Account und HTTP/Auth.
- Konfigurator: Geraete aus dem Account finden und Instanzen erzeugen.
- Device-Modul: ein Modul pro Appliance, z. B. Waschmaschine.

Empfohlene Properties:

- Account/Splitter: `Email`, `Password`, optional `RefreshToken`, `PollInterval`.
- Device: `MacAddress`, `ApplianceType`, `ApplianceModelId`, `Code`, `DisplayName`.

Empfohlene Attribute:

- `IdToken`
- `CognitoToken`
- `RefreshToken`
- `TokenTimestamp`
- `LastCommandsJson`
- `LastApplianceInfoJson`

Empfohlene Variablen fuer Waschmaschinen:

- `MachineStatus`
- `ProgramName`
- `ProgramPhase`
- `RemainingTime`
- `DoorStatus`
- `DoorLockStatus`
- `ErrorState`
- `RemoteControl`
- `CurrentWaterUsed`
- `CurrentElectricityUsed`
- `TotalWaterUsed`
- `TotalElectricityUsed`
- `TotalWashCycle`

Empfohlene Aktionen:

- `StartProgram`
- `StopProgram`
- `PauseProgram`
- `ResumeProgram`
- `RefreshState`

## Fehlerbehandlung

Typische Fehlerklassen:

- Login fehlgeschlagen.
- Token abgelaufen oder Refresh fehlgeschlagen.
- Geraet nicht im Account vorhanden.
- Geraet offline oder nicht remote steuerbar.
- Kommando vom Modell nicht unterstuetzt.
- Parameter ausserhalb erlaubter Werte.
- API liefert keinen JSON-Body.
- API liefert `resultCode` ungleich `"0"`.

Fehler sollten fuer Nutzer verstaendlich sein, intern aber genug Debug-Kontext enthalten:

- Endpunkt
- HTTP-Status
- Kommando
- Geraet
- gekuerzte, bereinigte Antwort

Nie loggen:

- Passwort
- `id-token`
- `cognito-token`
- `refresh_token`
- komplette Login-Redirect-URLs mit Tokens

## Polling-Strategie

Konservative Startwerte:

- Appliance-Liste: beim Start, manuell und selten zyklisch.
- Command-Definitionen: beim Start des Device-Moduls und nach Modell-/Firmwareaenderung.
- Laufzeitattribute: alle 60 bis 180 Sekunden, waehrend ein Programm laeuft optional haeufiger.
- Statistiken/Wartung: alle paar Stunden oder manuell.

Nach einem Schreibbefehl:

- 2 bis 5 Sekunden warten.
- Attribute neu laden.
- Bei ausbleibender Aenderung noch einmal nachladen.

## Minimaler Implementierungsablauf

1. Auth-Client bauen und nur `GET /commands/v1/appliance` testen.
2. Appliance-Daten lokal bereinigt ausgeben.
3. Fuer ein Geraet `GET /commands/v1/retrieve` und `GET /commands/v1/context` implementieren.
4. Dynamisches Mapping fuer Sensorwerte bauen.
5. Waschmaschinen-Device mit Lesezugriff stabilisieren.
6. `pauseProgram` und `resumeProgram` als erste Schreibbefehle testen.
7. `startProgram` erst implementieren, wenn Parameter-Validierung vollstaendig ist.
8. Danach Discovery, UI und Automationskomfort ergaenzen.

## Python-Beispiel mit pyhOn

Geraete listen:

```python
import asyncio
from pyhon import Hon

USER = "user@example.com"
PASSWORD = "secret"

async def main():
    async with Hon(USER, PASSWORD) as hon:
        for appliance in hon.appliances:
            print(appliance.nick_name)
            print(appliance.appliance_type)
            print(appliance.attributes)

asyncio.run(main())
```

Kommando senden:

```python
async with Hon(USER, PASSWORD) as hon:
    washing_machine = hon.appliances[0]
    pause = washing_machine.commands["pauseProgram"]
    await pause.send()
```

Parameter eines Startprogramms inspizieren:

```python
async with Hon(USER, PASSWORD) as hon:
    washing_machine = hon.appliances[0]
    start = washing_machine.commands["startProgram"]
    for name, setting in start.settings:
        print(name, setting.typology, setting.value)
```

## Offene Punkte vor einer produktiven Integration

- Aktuelle App-Version und API-Konstanten pruefen.
- Login gegen einen echten Testaccount validieren.
- Beispielantworten mehrerer Waschmaschinenmodelle sammeln.
- Geraetezustand fuer sichere Remote-Steuerung eindeutig bestimmen.
- Nutzungsbedingungen und rechtliche Risiken bewerten.
- Token-Speicherung der Zielplattform pruefen.
- Schreibbefehle erst mit realem Geraet und beaufsichtigtem Testlauf freigeben.
