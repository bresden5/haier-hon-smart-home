<?php

declare(strict_types=1);

class HaierhOnDevice extends IPSModuleStrict
{
    private const ACCOUNT_MODULE = '{48231829-1346-480B-A48E-13FAB565F458}';
    private const CHILD_TO_PARENT = '{3D4DC5E6-0F30-4F61-8EF3-85675A2DEF79}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DisplayName', '');
        $this->RegisterPropertyString('MacAddress', '');
        $this->RegisterPropertyString('ApplianceType', '');
        $this->RegisterPropertyString('ApplianceModelId', '');
        $this->RegisterPropertyString('Code', '');
        $this->RegisterPropertyString('FirmwareId', '');
        $this->RegisterPropertyString('FwVersion', '');
        $this->RegisterPropertyString('Series', '');
        $this->RegisterPropertyInteger('PollInterval', 120);

        $this->RegisterAttributeString('LastCommandsJson', '{}');
        $this->RegisterAttributeString('LastContextJson', '{}');
        $this->RegisterAttributeString('ProgramCodeMapJson', '{}');
        $this->RegisterAttributeString('LastError', '');

        $this->RegisterTimer('RefreshState', 0, 'HHOND_RefreshState($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->MaintainVariable('MachineStatus', 'Maschinenstatus', VARIABLETYPE_STRING, '', 10, true);
        $this->MaintainVariable('ProgramName', 'Programm', VARIABLETYPE_STRING, '', 20, true);
        $this->MaintainVariable('ProgramPhase', 'Programmphase', VARIABLETYPE_STRING, '', 30, true);
        $this->MaintainVariable('RemainingTime', 'Restzeit', VARIABLETYPE_INTEGER, '', 40, true);
        $this->MaintainVariable('RemainingMainWashTime', 'Restzeit Hauptwäsche', VARIABLETYPE_INTEGER, '', 45, true);
        $this->MaintainVariable('DoorStatus', 'Türstatus', VARIABLETYPE_STRING, '', 50, true);
        $this->MaintainVariable('DoorLockStatus', 'Türverriegelung', VARIABLETYPE_STRING, '', 60, true);
        $this->MaintainVariable('RemoteControl', 'Fernsteuerung', VARIABLETYPE_BOOLEAN, '~Switch', 70, true);
        $this->MaintainVariable('Paused', 'Pausiert', VARIABLETYPE_BOOLEAN, '~Switch', 75, true);
        $this->MaintainVariable('ErrorState', 'Fehler', VARIABLETYPE_STRING, '', 80, true);
        $this->MaintainVariable('Temperature', 'Temperatur', VARIABLETYPE_INTEGER, '', 85, true);
        $this->MaintainVariable('SpinSpeed', 'Schleuderdrehzahl', VARIABLETYPE_INTEGER, '', 90, true);
        $this->MaintainVariable('CurrentWaterUsed', 'Aktueller Wasserverbrauch', VARIABLETYPE_FLOAT, '', 100, true);
        $this->MaintainVariable('CurrentElectricityUsed', 'Aktueller Stromverbrauch', VARIABLETYPE_FLOAT, '', 110, true);
        $this->MaintainVariable('TotalWaterUsed', 'Wasserverbrauch gesamt', VARIABLETYPE_FLOAT, '', 120, true);
        $this->MaintainVariable('TotalElectricityUsed', 'Stromverbrauch gesamt', VARIABLETYPE_FLOAT, '', 130, true);
        $this->MaintainVariable('CurrentWashCycle', 'Aktueller Waschgang', VARIABLETYPE_INTEGER, '', 140, true);
        $this->MaintainVariable('TotalWashCycle', 'Waschgänge gesamt', VARIABLETYPE_INTEGER, '', 150, true);
        $this->MaintainVariable('ConnectionStatus', 'Verbindungsstatus', VARIABLETYPE_STRING, '', 160, true);

        $interval = $this->ReadPropertyInteger('PollInterval');
        $this->SetTimerInterval('RefreshState', $this->ReadPropertyString('MacAddress') === '' ? 0 : $interval * 1000);
        $this->SetStatus($this->ReadPropertyString('MacAddress') === '' ? 104 : 102);
    }

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'connect',
            'moduleIDs' => [self::ACCOUNT_MODULE]
        ], JSON_UNESCAPED_SLASHES);
    }

    public function ReceiveData(string $JSONString): string
    {
        $this->SendDebug('ReceiveData', $JSONString, 0);
        return '';
    }

    public function RefreshState(): bool
    {
        try {
            $context = $this->RequestParent('GET', '/commands/v1/context', [
                'macAddress' => $this->ReadPropertyString('MacAddress'),
                'applianceType' => $this->GetApplianceTypeName(),
                'category' => 'CYCLE'
            ]);
            $this->DebugJson('Status response', $context);
            $this->WriteAttributeString('LastContextJson', json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
            $this->UpdateVariables($context);
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus(102);
            return true;
        } catch (Throwable $exception) {
            $this->RememberError($exception->getMessage());
            return false;
        }
    }

    public function RefreshCommands(): bool
    {
        try {
            $commands = $this->RequestParent('GET', '/commands/v1/retrieve', $this->BuildDeviceQuery());
            $this->DebugJson('Command definitions response', $commands);
            $this->WriteAttributeString('LastCommandsJson', json_encode($commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
            $this->WriteAttributeString('ProgramCodeMapJson', json_encode($this->BuildProgramCodeMap($commands), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus(102);
            return true;
        } catch (Throwable $exception) {
            $this->RememberError($exception->getMessage());
            return false;
        }
    }

    public function PauseProgram(): bool
    {
        return $this->SendCommand('pauseProgram');
    }

    public function ResumeProgram(): bool
    {
        return $this->SendCommand('resumeProgram');
    }

    public function StopProgram(): bool
    {
        return $this->SendCommand('stopProgram');
    }

    public function StartProgram(string $programName, string $parametersJson = '{}'): bool
    {
        $parameters = json_decode($parametersJson, true);
        if (!is_array($parameters)) {
            $this->RememberError('Start parameters must be valid JSON');
            return false;
        }

        return $this->SendCommand('startProgram', $parameters, $programName);
    }

    public function GetLastContextJson(): string
    {
        return $this->ReadAttributeString('LastContextJson');
    }

    public function GetLastCommandsJson(): string
    {
        return $this->ReadAttributeString('LastCommandsJson');
    }

    public function GetLastError(): string
    {
        return $this->ReadAttributeString('LastError');
    }

    private function SendCommand(string $commandName, array $parameters = [], string $programName = ''): bool
    {
        try {
            if ($this->ReadAttributeString('LastCommandsJson') === '{}') {
                $this->RefreshCommands();
            }

            if (!$this->CommandExists($commandName)) {
                throw new RuntimeException('Command is not supported by this appliance: ' . $commandName);
            }

            $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
            $mac = $this->ReadPropertyString('MacAddress');
            $body = [
                'macAddress' => $mac,
                'timestamp' => $timestamp,
                'commandName' => $commandName,
                'transactionId' => $mac . '_' . $timestamp,
                'applianceOptions' => new stdClass(),
                'device' => new stdClass(),
                'attributes' => [
                    'channel' => 'mobileApp',
                    'origin' => 'standardProgram',
                    'energyLabel' => '0'
                ],
                'ancillaryParameters' => new stdClass(),
                'parameters' => $parameters,
                'applianceType' => $this->GetApplianceTypeName()
            ];

            if ($programName !== '') {
                $body['programName'] = $programName;
            }

            $response = $this->RequestParent('POST', '/commands/v1/send', [], $body);
            $resultCode = (string) ($response['payload']['resultCode'] ?? '');
            if ($resultCode !== '0') {
                throw new RuntimeException('hOn rejected command ' . $commandName . ' with resultCode ' . $resultCode);
            }

            IPS_Sleep(3000);
            $this->RefreshState();
            return true;
        } catch (Throwable $exception) {
            $this->RememberError($exception->getMessage());
            return false;
        }
    }

    private function RequestParent(string $method, string $endpoint, array $query = [], ?array $body = null): array
    {
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::CHILD_TO_PARENT,
            'Action' => 'Request',
            'Method' => $method,
            'Endpoint' => $endpoint,
            'Query' => $query,
            'Body' => $body
        ], JSON_UNESCAPED_SLASHES));

        $data = json_decode((string) $response, true);
        if (!is_array($data) || !($data['success'] ?? false)) {
            throw new RuntimeException((string) ($data['error'] ?? 'Parent request failed'));
        }

        return is_array($data['payload'] ?? null) ? $data['payload'] : [];
    }

    private function BuildDeviceQuery(): array
    {
        return array_filter([
            'applianceType' => $this->GetApplianceTypeId(),
            'applianceModelId' => $this->ReadPropertyString('ApplianceModelId'),
            'macAddress' => $this->ReadPropertyString('MacAddress'),
            'os' => 'android',
            'appVersion' => '2.27.9',
            'code' => $this->ReadPropertyString('Code'),
            'firmwareId' => $this->ReadPropertyString('FirmwareId'),
            'fwVersion' => $this->ReadPropertyString('FwVersion'),
            'series' => $this->ReadPropertyString('Series')
        ], static fn ($value): bool => (string) $value !== '');
    }

    private function GetApplianceTypeId(): string
    {
        $type = $this->ReadPropertyString('ApplianceType');
        if (is_numeric($type)) {
            return (string) $type;
        }

        return (string) (array_flip($this->GetApplianceTypeMap())[strtoupper($type)] ?? $type);
    }

    private function GetApplianceTypeName(): string
    {
        $type = $this->ReadPropertyString('ApplianceType');
        if (!is_numeric($type)) {
            return strtoupper($type);
        }

        return (string) ($this->GetApplianceTypeMap()[$type] ?? $type);
    }

    private function GetApplianceTypeMap(): array
    {
        return [
            '1' => 'WM',
            '2' => 'WD',
            '4' => 'OV',
            '6' => 'WC',
            '7' => 'AP',
            '8' => 'TD',
            '9' => 'DW',
            '10' => 'WH',
            '11' => 'AC',
            '14' => 'REF',
            '25' => 'TV',
            '27' => 'ATW'
        ];
    }

    private function CommandExists(string $commandName): bool
    {
        $commands = json_decode($this->ReadAttributeString('LastCommandsJson'), true);
        if (!is_array($commands)) {
            return false;
        }

        $haystack = $commands['payload']['commands'] ?? $commands['commands'] ?? $commands;
        if (is_array($haystack) && array_key_exists($commandName, $haystack)) {
            return true;
        }

        if (!is_array($haystack)) {
            return false;
        }

        foreach ($haystack as $command) {
            if (is_array($command) && (($command['commandName'] ?? $command['name'] ?? '') === $commandName)) {
                return true;
            }
        }

        return false;
    }

    private function UpdateVariables(array $context): void
    {
        $flat = $this->Flatten($context);
        $parameters = $this->ExtractShadowParameters($context);

        $this->SetStringIfAvailable('MachineStatus', $flat, ['payload.attributes.machMode', 'attributes.machMode', 'machMode'], $this->TranslateMachineStatus($parameters['machMode'] ?? null));
        $this->SetStringIfAvailable('ProgramName', $flat, ['payload.attributes.programName', 'attributes.programName', 'programName'], $this->TranslateProgramCode($parameters['prCode'] ?? null));
        $this->SetStringIfAvailable('ProgramPhase', $flat, ['payload.attributes.prPhase', 'attributes.prPhase', 'prPhase'], $this->TranslateProgramPhase($parameters['prPhase'] ?? null));
        $this->SetIntegerIfAvailable('RemainingTime', $flat, ['payload.attributes.remainingTimeMM', 'attributes.remainingTimeMM', 'remainingTimeMM'], $parameters['remainingTimeMM'] ?? null);
        $this->SetIntegerIfAvailable('RemainingMainWashTime', $flat, ['payload.attributes.remainingMainWashTime', 'attributes.remainingMainWashTime', 'remainingMainWashTime'], $parameters['remainingMainWashTime'] ?? null);
        $this->SetStringIfAvailable('DoorStatus', $flat, ['payload.attributes.doorStatus', 'attributes.doorStatus', 'doorStatus'], $this->TranslateBinaryCode($parameters['doorStatus'] ?? null, 'Geschlossen', 'Offen'));
        $this->SetStringIfAvailable('DoorLockStatus', $flat, ['payload.attributes.doorLockStatus', 'attributes.doorLockStatus', 'doorLockStatus'], $this->TranslateBinaryCode($parameters['doorLockStatus'] ?? null, 'Verriegelt', 'Entriegelt'));
        $this->SetStringIfAvailable('ErrorState', $flat, ['payload.attributes.errors', 'attributes.errors', 'errors'], $this->TranslateErrorState($parameters['errors'] ?? null));
        $this->SetBooleanIfAvailable('RemoteControl', $flat, ['payload.attributes.remoteCtrValid', 'attributes.remoteCtrValid', 'remoteCtrValid'], $parameters['remoteCtrValid'] ?? null);
        $this->SetBooleanIfAvailable('Paused', $flat, ['payload.attributes.pause', 'attributes.pause', 'pause'], $parameters['pause'] ?? null);
        $this->SetIntegerIfAvailable('Temperature', $flat, ['payload.attributes.temp', 'attributes.temp', 'temp'], $parameters['temp'] ?? null);
        $this->SetIntegerIfAvailable('SpinSpeed', $flat, ['payload.attributes.spinSpeed', 'attributes.spinSpeed', 'spinSpeed'], $parameters['spinSpeed'] ?? null);
        $this->SetFloatIfAvailable('CurrentWaterUsed', $flat, ['payload.attributes.currentWaterUsed', 'attributes.currentWaterUsed', 'currentWaterUsed'], $parameters['currentWaterUsed'] ?? null);
        $this->SetFloatIfAvailable('CurrentElectricityUsed', $flat, ['payload.attributes.currentElectricityUsed', 'attributes.currentElectricityUsed', 'currentElectricityUsed'], $parameters['currentElectricityUsed'] ?? null);
        $this->SetFloatIfAvailable('TotalWaterUsed', $flat, ['payload.attributes.totalWaterUsed', 'attributes.totalWaterUsed', 'totalWaterUsed'], $parameters['totalWaterUsed'] ?? null);
        $this->SetFloatIfAvailable('TotalElectricityUsed', $flat, ['payload.attributes.totalElectricityUsed', 'attributes.totalElectricityUsed', 'totalElectricityUsed'], $parameters['totalElectricityUsed'] ?? null);
        $this->SetIntegerIfAvailable('CurrentWashCycle', $flat, ['payload.attributes.currentWashCycle', 'attributes.currentWashCycle', 'currentWashCycle'], $parameters['currentWashCycle'] ?? null);
        $this->SetIntegerIfAvailable('TotalWashCycle', $flat, ['payload.attributes.totalWashCycle', 'attributes.totalWashCycle', 'totalWashCycle'], $parameters['totalWashCycle'] ?? null);
        $this->SetStringIfAvailable('ConnectionStatus', $flat, ['payload.lastConnEvent.category', 'lastConnEvent.category'], $this->TranslateConnectionStatus($flat['payload.lastConnEvent.category'] ?? $flat['lastConnEvent.category'] ?? null));
    }

    private function BuildProgramCodeMap(array $commands): array
    {
        $programs = $commands['payload']['startProgram'] ?? [];
        if (!is_array($programs)) {
            return [];
        }

        $map = [];
        foreach ($programs as $programName => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $code = $definition['parameters']['prCode']['fixedValue'] ?? null;
            if ($code === null || (string) $code === '') {
                continue;
            }

            $map[(string) $code] = $this->FormatProgramName((string) $programName);
        }

        ksort($map, SORT_NATURAL);
        return $map;
    }

    private function FormatProgramName(string $programName): string
    {
        $programName = preg_replace('/^PROGRAMS\.[^.]+\./', '', $programName) ?? $programName;
        $programName = preg_replace('/^(HQD|IOT_WASH)_/', '', $programName) ?? $programName;
        $programName = strtoupper($programName);

        $specificNames = [
            'SMART' => 'Smart',
            'QUICK_15' => 'Schnell 15',
            'SPIN' => 'Schleudern',
            'COTTONS' => 'Baumwolle',
            'COTTON' => 'Baumwolle',
            'SYNTHETIC_AND_COLOURED' => 'Synthetik und Buntwäsche',
            'SYNTHETIC' => 'Synthetik',
            'HANDWASH_WOOL' => 'Handwäsche / Wolle',
            'WOOL' => 'Wolle',
            '20_DEGREES' => '20 Grad',
            'ECO_40_60_DEGREES' => 'Eco 40-60 Grad',
            'MIX' => 'Mix',
            'MIXED' => 'Mix',
            'ALLERGY' => 'Allergie',
            'REFRESH' => 'Auffrischen',
            'DUVET' => 'Bettdecke',
            'SHIRTS' => 'Hemden',
            'AUTOCLEAN' => 'Trommelreinigung',
            'SPORT' => 'Sport',
            'BABYCARE' => 'Babywäsche',
            'CHECKUP' => 'Checkup',
            'DELICATE_CRADLE' => 'Feinwäsche',
            'DELICATE' => 'Feinwäsche',
            'QUICK_WASH_57' => 'Schnellwäsche 57',
            'RINSE' => 'Spülen',
            'COLOURED' => 'Buntwäsche',
            'COLORED' => 'Buntwäsche',
            'RAPID_14' => 'Schnell 14',
            'RAPID_30' => 'Schnell 30',
            'RAPID_44' => 'Schnell 44',
            'RAPID_59' => 'Schnell 59',
            'BABY_SANITIZER' => 'Baby-Hygiene',
            'BED_LINEN' => 'Bettwäsche',
            'CASHMERE' => 'Kaschmir',
            'CURTAINS' => 'Gardinen',
            'DENIM_JEANS' => 'Jeans',
            'PERFECT_WHITE' => 'Perfektes Weiß',
            'ANTI_MITES' => 'Anti-Milben',
            'SPORT_ANTI_ODOR' => 'Sport Anti-Geruch',
            'PETS' => 'Haustiere',
            'NEW_CLOTHES' => 'Neue Kleidung',
            'LINGERIE' => 'Dessous',
            'MATS' => 'Matten',
            'TRAINERS' => 'Turnschuhe',
            'SILK' => 'Seide',
            'DARK' => 'Dunkle Wäsche',
            'COLD_WASH' => 'Kaltwäsche',
            'WHITES' => 'Weiße Wäsche',
            'HANDWASH' => 'Handwäsche'
        ];

        if (isset($specificNames[$programName])) {
            return $specificNames[$programName];
        }

        $words = explode('_', $programName);
        $translations = [
            'AND' => 'und',
            'ANTI' => 'Anti',
            'ARIEL' => 'Ariel',
            'BACKPACKS' => 'Rucksäcke',
            'BATHROBE' => 'Bademantel',
            'BED' => 'Bett',
            'BLEACHING' => 'Bleichen',
            'BLOOD' => 'Blut',
            'CARE' => 'Pflege',
            'CASHMERE' => 'Kaschmir',
            'CHOCOLATE' => 'Schokolade',
            'CLEAN' => 'Reinigen',
            'CLOTHES' => 'Kleidung',
            'COLD' => 'Kalt',
            'COLORED' => 'Buntwäsche',
            'COLOURED' => 'Buntwäsche',
            'COLORS' => 'Farben',
            'COTTON' => 'Baumwolle',
            'COTTONS' => 'Baumwolle',
            'CUDDLY' => 'Kuscheltiere',
            'CURTAINS' => 'Gardinen',
            'DARK' => 'Dunkel',
            'DEGREES' => 'Grad',
            'DELICATE' => 'Feinwäsche',
            'DENIM' => 'Denim',
            'DOWN' => 'Daunen',
            'DUVET' => 'Bettdecke',
            'ECO' => 'Eco',
            'FABRICS' => 'Textilien',
            'FRESH' => 'Frisch',
            'FRUIT' => 'Obst',
            'HANDWASH' => 'Handwäsche',
            'JACKETS' => 'Jacken',
            'JEANS' => 'Jeans',
            'LINEN' => 'Wäsche',
            'LINGERIE' => 'Dessous',
            'MASKS' => 'Masken',
            'MATS' => 'Matten',
            'MEN' => 'Herren',
            'MIXED' => 'Mix',
            'NEW' => 'Neu',
            'ODOR' => 'Geruch',
            'ODOURS' => 'Gerüche',
            'PETS' => 'Haustiere',
            'RAPID' => 'Schnell',
            'REFRESH' => 'Auffrischen',
            'REMOVAL' => 'Entfernung',
            'RESISTANT' => 'Strapazierfähig',
            'RINSE' => 'Spülen',
            'SANIFICATION' => 'Hygiene',
            'SILK' => 'Seide',
            'SKI' => 'Ski',
            'SPIN' => 'Schleudern',
            'SPORT' => 'Sport',
            'STAINS' => 'Flecken',
            'SUIT' => 'Anzug',
            'SWIMSUITS' => 'Badebekleidung',
            'SYNTHETIC' => 'Synthetik',
            'TABLECLOTHS' => 'Tischdecken',
            'TECHNICAL' => 'Technische',
            'TRAINERS' => 'Turnschuhe',
            'TROUSERS' => 'Hosen',
            'WASH' => 'Wäsche',
            'WHITE' => 'Weiß',
            'WHITES' => 'Weiße Wäsche',
            'WINE' => 'Wein',
            'WOOL' => 'Wolle'
        ];

        return implode(' ', array_map(
            static fn (string $word): string => $translations[$word] ?? ucwords(strtolower($word)),
            $words
        ));
    }

    private function TranslateMachineStatus(mixed $value): ?string
    {
        return $this->TranslateCode($value, [
            '0' => 'Getrennt',
            '1' => 'Bereit',
            '2' => 'Läuft',
            '3' => 'Pausiert',
            '4' => 'Geplant',
            '5' => 'Geplant',
            '6' => 'Fehler',
            '7' => 'Beendet',
            '8' => 'Test',
            '9' => 'Beenden'
        ], 'Status');
    }

    private function TranslateProgramPhase(mixed $value): ?string
    {
        return $this->TranslateCode($value, [
            '0' => 'Bereit',
            '1' => 'Waschen',
            '2' => 'Waschen',
            '3' => 'Schleudern',
            '4' => 'Spülen',
            '5' => 'Spülen',
            '6' => 'Spülen',
            '7' => 'Trocknen',
            '8' => 'Trocknen',
            '9' => 'Dampf',
            '10' => 'Bereit',
            '11' => 'Schleudern',
            '12' => 'Wiegen',
            '13' => 'Wiegen',
            '14' => 'Waschen',
            '15' => 'Waschen',
            '16' => 'Waschen',
            '17' => 'Spülen',
            '18' => 'Spülen',
            '19' => 'Geplant',
            '20' => 'Trommelbewegung',
            '24' => 'Aktualisieren',
            '25' => 'Waschen',
            '26' => 'Heizen',
            '27' => 'Waschen'
        ], 'Phase');
    }

    private function TranslateProgramCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $code = (string) $value;
        $map = json_decode($this->ReadAttributeString('ProgramCodeMapJson'), true);
        if (is_array($map) && isset($map[$code])) {
            return $map[$code] . ' (' . $code . ')';
        }

        return 'Programmcode ' . $code;
    }

    private function TranslateErrorState(mixed $value): ?string
    {
        return $this->TranslateCode($value, [
            '00' => 'Keine',
            '100000000000' => 'E2: Tür prüfen',
            '8000000000000' => 'E4: Wasserversorgung prüfen',
            '400000000000000' => 'Unwucht: Wäsche prüfen'
        ], 'Fehler');
    }

    private function TranslateConnectionStatus(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (strtoupper((string) $value)) {
            'CONNECTED' => 'Verbunden',
            'DISCONNECTED' => 'Getrennt',
            default => (string) $value
        };
    }

    private function TranslateBinaryCode(mixed $value, string $zeroLabel, string $oneLabel): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ((string) $value) {
            '0' => $zeroLabel,
            '1' => $oneLabel,
            default => 'Code ' . (string) $value
        };
    }

    private function TranslateCode(mixed $value, array $map, string $unknownPrefix): ?string
    {
        if ($value === null) {
            return null;
        }

        $code = (string) $value;
        return isset($map[$code]) ? $map[$code] . ' (' . $code . ')' : $unknownPrefix . ' ' . $code;
    }

    private function ExtractShadowParameters(array $context): array
    {
        $parameters = $context['payload']['shadow']['parameters'] ?? [];
        if (!is_array($parameters)) {
            return [];
        }

        $values = [];
        foreach ($parameters as $name => $parameter) {
            if (is_array($parameter) && array_key_exists('parNewVal', $parameter)) {
                $values[(string) $name] = $parameter['parNewVal'];
            }
        }

        return $values;
    }

    private function Flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $result += $this->Flatten($value, $path);
            } else {
                $result[$path] = $value;
            }
        }
        return $result;
    }

    private function FindValue(array $flat, array $paths): mixed
    {
        foreach ($paths as $path) {
            if (array_key_exists($path, $flat)) {
                return $flat[$path];
            }
        }
        return null;
    }

    private function SetStringIfAvailable(string $ident, array $flat, array $paths, mixed $fallback = null): void
    {
        $value = $fallback ?? $this->FindValue($flat, $paths);
        if ($value !== null) {
            $this->SetValue($ident, is_scalar($value) ? (string) $value : json_encode($value));
        }
    }

    private function SetIntegerIfAvailable(string $ident, array $flat, array $paths, mixed $fallback = null): void
    {
        $value = $fallback ?? $this->FindValue($flat, $paths);
        if ($value !== null && is_numeric($value)) {
            $this->SetValue($ident, (int) $value);
        }
    }

    private function SetFloatIfAvailable(string $ident, array $flat, array $paths, mixed $fallback = null): void
    {
        $value = $fallback ?? $this->FindValue($flat, $paths);
        if ($value !== null && is_numeric($value)) {
            $this->SetValue($ident, (float) $value);
        }
    }

    private function SetBooleanIfAvailable(string $ident, array $flat, array $paths, mixed $fallback = null): void
    {
        $value = $fallback ?? $this->FindValue($flat, $paths);
        if ($value !== null) {
            $this->SetValue($ident, in_array(strtolower((string) $value), ['1', 'true', 'mobileapp', 'connected', 'online', 'remote'], true));
        }
    }

    private function RememberError(string $message): void
    {
        $this->WriteAttributeString('LastError', $message);
        $this->SendDebug('hOn error', $message, 0);
        $this->SetStatus(202);
    }

    private function DebugJson(string $title, array $data): void
    {
        $json = json_encode($this->SanitizeDebugData($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->SendDebug($title, $json === false ? '[JSON encode failed]' : $json, 0);
    }

    private function SanitizeDebugData(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $lowerKey = strtolower((string) $key);
                if (str_contains($lowerKey, 'token') || str_contains($lowerKey, 'password') || str_contains($lowerKey, 'secret')) {
                    $result[$key] = '[redacted]';
                    continue;
                }

                $result[$key] = $this->SanitizeDebugData($item);
            }

            return $result;
        }

        return $value;
    }
}
