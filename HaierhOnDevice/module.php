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
        $this->RegisterAttributeString('LastError', '');

        $this->RegisterTimer('RefreshState', 0, 'HHOND_RefreshState($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->MaintainVariable('MachineStatus', 'Machine status', VARIABLETYPE_STRING, '', 10, true);
        $this->MaintainVariable('ProgramName', 'Program', VARIABLETYPE_STRING, '', 20, true);
        $this->MaintainVariable('ProgramPhase', 'Program phase', VARIABLETYPE_STRING, '', 30, true);
        $this->MaintainVariable('RemainingTime', 'Remaining time', VARIABLETYPE_INTEGER, '', 40, true);
        $this->MaintainVariable('DoorStatus', 'Door status', VARIABLETYPE_STRING, '', 50, true);
        $this->MaintainVariable('DoorLockStatus', 'Door lock', VARIABLETYPE_STRING, '', 60, true);
        $this->MaintainVariable('RemoteControl', 'Remote control', VARIABLETYPE_BOOLEAN, '~Switch', 70, true);
        $this->MaintainVariable('ErrorState', 'Errors', VARIABLETYPE_STRING, '', 80, true);
        $this->MaintainVariable('CurrentWaterUsed', 'Current water used', VARIABLETYPE_FLOAT, '', 90, true);
        $this->MaintainVariable('CurrentElectricityUsed', 'Current electricity used', VARIABLETYPE_FLOAT, '', 100, true);
        $this->MaintainVariable('TotalWaterUsed', 'Total water used', VARIABLETYPE_FLOAT, '', 110, true);
        $this->MaintainVariable('TotalElectricityUsed', 'Total electricity used', VARIABLETYPE_FLOAT, '', 120, true);
        $this->MaintainVariable('TotalWashCycle', 'Total wash cycles', VARIABLETYPE_INTEGER, '', 130, true);

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
            $this->WriteAttributeString('LastCommandsJson', json_encode($commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
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

        $this->SetStringIfAvailable('MachineStatus', $flat, ['payload.attributes.machMode', 'attributes.machMode', 'machMode']);
        $this->SetStringIfAvailable('ProgramName', $flat, ['payload.attributes.programName', 'attributes.programName', 'programName']);
        $this->SetStringIfAvailable('ProgramPhase', $flat, ['payload.attributes.prPhase', 'attributes.prPhase', 'prPhase']);
        $this->SetIntegerIfAvailable('RemainingTime', $flat, ['payload.attributes.remainingTimeMM', 'attributes.remainingTimeMM', 'remainingTimeMM']);
        $this->SetStringIfAvailable('DoorStatus', $flat, ['payload.attributes.doorStatus', 'attributes.doorStatus', 'doorStatus']);
        $this->SetStringIfAvailable('DoorLockStatus', $flat, ['payload.attributes.doorLockStatus', 'attributes.doorLockStatus', 'doorLockStatus']);
        $this->SetStringIfAvailable('ErrorState', $flat, ['payload.attributes.errors', 'attributes.errors', 'errors']);
        $this->SetBooleanIfAvailable('RemoteControl', $flat, ['payload.attributes.lastConnEvent.category', 'attributes.lastConnEvent.category', 'lastConnEvent.category']);
        $this->SetFloatIfAvailable('CurrentWaterUsed', $flat, ['payload.attributes.currentWaterUsed', 'attributes.currentWaterUsed', 'currentWaterUsed']);
        $this->SetFloatIfAvailable('CurrentElectricityUsed', $flat, ['payload.attributes.currentElectricityUsed', 'attributes.currentElectricityUsed', 'currentElectricityUsed']);
        $this->SetFloatIfAvailable('TotalWaterUsed', $flat, ['payload.attributes.totalWaterUsed', 'attributes.totalWaterUsed', 'totalWaterUsed']);
        $this->SetFloatIfAvailable('TotalElectricityUsed', $flat, ['payload.attributes.totalElectricityUsed', 'attributes.totalElectricityUsed', 'totalElectricityUsed']);
        $this->SetIntegerIfAvailable('TotalWashCycle', $flat, ['payload.attributes.totalWashCycle', 'attributes.totalWashCycle', 'totalWashCycle']);
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

    private function SetStringIfAvailable(string $ident, array $flat, array $paths): void
    {
        $value = $this->FindValue($flat, $paths);
        if ($value !== null) {
            $this->SetValue($ident, is_scalar($value) ? (string) $value : json_encode($value));
        }
    }

    private function SetIntegerIfAvailable(string $ident, array $flat, array $paths): void
    {
        $value = $this->FindValue($flat, $paths);
        if ($value !== null && is_numeric($value)) {
            $this->SetValue($ident, (int) $value);
        }
    }

    private function SetFloatIfAvailable(string $ident, array $flat, array $paths): void
    {
        $value = $this->FindValue($flat, $paths);
        if ($value !== null && is_numeric($value)) {
            $this->SetValue($ident, (float) $value);
        }
    }

    private function SetBooleanIfAvailable(string $ident, array $flat, array $paths): void
    {
        $value = $this->FindValue($flat, $paths);
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
}
