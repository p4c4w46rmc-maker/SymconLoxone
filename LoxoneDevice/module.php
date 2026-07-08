<?php

declare(strict_types=1);

class LoxoneDevice extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('GatewayID', 0);
        $this->RegisterPropertyString('ControlUUID', '');
        $this->RegisterPropertyString('ActionUUID', '');
        $this->RegisterPropertyString('ControlName', '');
        $this->RegisterPropertyString('ControlType', 'Unknown');
        $this->RegisterPropertyString('RoomName', '');
        $this->RegisterPropertyString('CategoryName', '');
        $this->RegisterPropertyString('StatesJson', '{}');
        $this->RegisterPropertyString('DetailsJson', '{}');

        $this->RegisterVariableString('ControlUUID', 'Control UUID', '', 10);
        $this->RegisterVariableString('ActionUUID', 'Action UUID', '', 20);
        $this->RegisterVariableString('ControlType', 'Control Typ', '', 30);
        $this->RegisterVariableString('RoomName', 'Raum', '', 40);
        $this->RegisterVariableString('CategoryName', 'Kategorie', '', 50);
        $this->RegisterVariableString('GatewayIDText', 'Gateway Instanz', '', 60);

        // Sprint 12: Frontend variables are created dynamically from the Loxone control type.
        // Technical metadata and raw state variables remain available in the object tree,
        // but are hidden from WebFront/visualization by default.
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $name = trim($this->ReadPropertyString('ControlName'));
        if ($name !== '') {
            IPS_SetName($this->InstanceID, $name);
        }

        $this->SetValue('ControlUUID', $this->ReadPropertyString('ControlUUID'));
        $this->SetValue('ActionUUID', $this->ReadPropertyString('ActionUUID'));
        $this->SetValue('ControlType', $this->ReadPropertyString('ControlType'));
        $this->SetValue('RoomName', $this->ReadPropertyString('RoomName'));
        $this->SetValue('CategoryName', $this->ReadPropertyString('CategoryName'));
        $this->SetValue('GatewayIDText', (string)$this->ReadPropertyInteger('GatewayID'));

        $this->RegisterPresentationVariables();
        $this->RegisterStateVariables();
        $this->ApplyVisibilityPolicy();
        $this->SetSummary($this->ReadPropertyString('ControlType'));
    }

    public function RefreshMetadata()
    {
        $this->ApplyChanges();

        $states = $this->DecodeJsonObject($this->ReadPropertyString('StatesJson'));
        return "Metadaten aktualisiert\n" .
            "Control: " . $this->ReadPropertyString('ControlName') . "\n" .
            "Typ: " . $this->ReadPropertyString('ControlType') . "\n" .
            "Raum: " . $this->ReadPropertyString('RoomName') . "\n" .
            "Kategorie: " . $this->ReadPropertyString('CategoryName') . "\n" .
            "States: " . count($states);
    }



    public function RefreshStateValues()
    {
        $gatewayId = $this->ReadPropertyInteger('GatewayID');
        if ($gatewayId <= 0 || !IPS_InstanceExists($gatewayId)) {
            return "Kein gültiges Gateway hinterlegt.";
        }

        $states = $this->DecodeJsonObject($this->ReadPropertyString('StatesJson'));
        $updated = 0;
        $errors = [];

        foreach ($states as $stateName => $stateUuid) {
            $stateName = (string)$stateName;
            $stateUuid = (string)$stateUuid;
            $ident = 'State_' . $this->IdentFromString($stateName);

            if (!$this->HasVariableWithIdent($ident)) {
                continue;
            }

            try {
                $rawValue = LOX_GetStateValue($gatewayId, $stateUuid);
                $this->SetTypedStateValue($ident, $rawValue);
                $updated++;
            } catch (Throwable $e) {
                $errors[] = $stateName . ': ' . $e->getMessage();
            }
        }

        $text = "Statuswerte aktualisiert
" .
            "Control: " . $this->ReadPropertyString('ControlName') . "
" .
            "Aktualisiert: " . $updated;

        if (count($errors) > 0) {
            $text .= "
Fehler: " . count($errors) . "
" . implode("
", array_slice($errors, 0, 5));
        }

        return $text;
    }


    public function RequestAction($ident, $value)
    {
        $ident = (string)$ident;
        $controlType = strtolower($this->ReadPropertyString('ControlType'));

        if ($ident === 'Display_Switch' || $ident === 'State_active') {
            if ($controlType === 'switch') {
                if ((bool)$value) {
                    $this->SwitchOn();
                } else {
                    $this->SwitchOff();
                }
                $this->SetValueIfExists('Display_Switch', (bool)$value);
                $this->SetValueIfExists('State_active', (bool)$value);
                return;
            }

            if ($controlType === 'pushbutton') {
                $this->Press();
                $this->SetValueIfExists('Display_Press', false);
                return;
            }
        }

        if ($ident === 'Display_Press') {
            $this->Press();
            $this->SetValueIfExists('Display_Press', false);
            return;
        }

        throw new Exception('Keine Aktion für ' . $ident . ' verfügbar.');
    }

    public function SendCommand(string $command)
    {
        $gatewayId = $this->ReadPropertyInteger('GatewayID');
        if ($gatewayId <= 0 || !IPS_InstanceExists($gatewayId)) {
            throw new RuntimeException('Kein gültiges Gateway hinterlegt.');
        }

        $actionUuid = trim($this->ReadPropertyString('ActionUUID'));
        if ($actionUuid === '') {
            throw new RuntimeException('Keine ActionUUID hinterlegt.');
        }

        return LOX_SendControlCommand($gatewayId, $actionUuid, $command);
    }

    public function Press()
    {
        return $this->SendCommand('pulse');
    }

    public function SwitchOn()
    {
        return $this->SendCommand('on');
    }

    public function SwitchOff()
    {
        return $this->SendCommand('off');
    }

    public function Toggle()
    {
        $controlType = strtolower($this->ReadPropertyString('ControlType'));
        if ($controlType === 'pushbutton') {
            return $this->Press();
        }

        return $this->SendCommand('pulse');
    }

    public function TestCommand()
    {
        $type = strtolower($this->ReadPropertyString('ControlType'));
        try {
            if ($type === 'switch') {
                $result = $this->Toggle();
                $command = 'pulse';
            } elseif ($type === 'pushbutton') {
                $result = $this->Press();
                $command = 'pulse';
            } else {
                return "Für diesen Control-Typ ist in Sprint 11 noch kein Standardbefehl hinterlegt.\n" .
                    "Typ: " . $this->ReadPropertyString('ControlType');
            }

            return "Befehl gesendet\n" .
                "Control: " . $this->ReadPropertyString('ControlName') . "\n" .
                "Typ: " . $this->ReadPropertyString('ControlType') . "\n" .
                "ActionUUID: " . $this->ReadPropertyString('ActionUUID') . "\n" .
                "Befehl: " . $command . "\n" .
                "Antwort: " . (is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : (string)$result);
        } catch (Throwable $e) {
            return "Befehl fehlgeschlagen\n" .
                "Control: " . $this->ReadPropertyString('ControlName') . "\n" .
                "Typ: " . $this->ReadPropertyString('ControlType') . "\n" .
                "Fehler: " . $e->getMessage();
        }
    }

    public function UpdatePresentationValue(string $stateName, $rawValue)
    {
        $this->SetPresentationValueFromState($stateName, $rawValue);
        return true;
    }

    private function RegisterPresentationVariables(): void
    {
        $type = strtolower($this->ReadPropertyString('ControlType'));
        $name = $this->ReadPropertyString('ControlName');
        $category = $this->ReadPropertyString('CategoryName');
        $states = $this->DecodeJsonObject($this->ReadPropertyString('StatesJson'));
        $class = $this->DetectDeviceClass();

        if ($type === 'switch' && isset($states['active'])) {
            $caption = $class === 'gate' ? 'Tor schalten' : 'Schalter';
            $this->RegisterVariableBoolean('Display_Switch', $caption, '~Switch', 1);
            $this->SetVariableCaption('Display_Switch', $caption);
            $this->EnableAction('Display_Switch');
            $this->SetDefaultValueIfEmpty('Display_Switch', false);
        }

        if ($type === 'pushbutton') {
            $caption = $this->ActionCaptionForDeviceClass($class, $name);
            $this->RegisterVariableBoolean('Display_Press', $caption, '~Switch', 1);
            $this->SetVariableCaption('Display_Press', $caption);
            $this->EnableAction('Display_Press');
            $this->SetValueIfExists('Display_Press', false);
        }

        if ($type === 'infoonlydigital' && isset($states['active'])) {
            $caption = $this->StatusCaptionForDeviceClass($class, $name);
            $this->RegisterVariableBoolean('Display_Status', $caption, '~Switch', 1);
            $this->SetVariableCaption('Display_Status', $caption);
            $this->SetDefaultValueIfEmpty('Display_Status', false);
        }

        if ($type === 'daytimer') {
            if (isset($states['value'])) {
                $this->RegisterVariableBoolean('Display_DaytimerActive', 'Aktiv', '~Switch', 20);
                $this->SetVariableCaption('Display_DaytimerActive', 'Aktiv');
                $this->SetDefaultValueIfEmpty('Display_DaytimerActive', false);
            }
            if (isset($states['override'])) {
                $this->RegisterVariableBoolean('Display_DaytimerOverride', 'Override', '~Switch', 21);
                $this->SetVariableCaption('Display_DaytimerOverride', 'Override');
                $this->SetDefaultValueIfEmpty('Display_DaytimerOverride', false);
            }
        }

        if (isset($states['jLocked'])) {
            $caption = $class === 'nfc' ? 'Bedienung gesperrt' : 'Gesperrt';
            $this->RegisterVariableBoolean('Display_Locked', $caption, '~Switch', 10);
            $this->SetVariableCaption('Display_Locked', $caption);
            $this->SetDefaultValueIfEmpty('Display_Locked', false);
        }

        if (isset($states['lockedOn'])) {
            $this->RegisterVariableBoolean('Display_LockedOn', 'Gesperrt ein', '~Switch', 11);
            $this->SetVariableCaption('Display_LockedOn', 'Gesperrt ein');
            $this->SetDefaultValueIfEmpty('Display_LockedOn', false);
        }

        if (isset($states['deviceState']) && in_array($class, ['nfc', 'access'], true)) {
            $this->RegisterVariableInteger('Display_DeviceState', 'Gerätestatus', '', 20);
            $this->SetVariableCaption('Display_DeviceState', 'Gerätestatus');
            $this->SetDefaultValueIfEmpty('Display_DeviceState', 0);
        }

        // Generic mode/value are useful for unknown controls, but too noisy for Daytimer/NFC/Gate views.
        if (isset($states['mode']) && !in_array($class, ['daytimer', 'nfc', 'gate', 'access'], true)) {
            $this->RegisterVariableInteger('Display_Mode', 'Modus', '', 21);
            $this->SetVariableCaption('Display_Mode', 'Modus');
            $this->SetDefaultValueIfEmpty('Display_Mode', 0);
        }

        if (isset($states['value']) && !in_array($class, ['daytimer'], true)) {
            $this->RegisterVariableFloat('Display_Value', 'Wert', '', 22);
            $this->SetVariableCaption('Display_Value', 'Wert');
            $this->SetDefaultValueIfEmpty('Display_Value', 0.0);
        }

        if (isset($states['lastuser'])) {
            $this->RegisterVariableString('Display_LastUser', 'Letzter Benutzer', '', 30);
            $this->SetVariableCaption('Display_LastUser', 'Letzter Benutzer');
        }

        if (isset($states['lasttag'])) {
            $this->RegisterVariableString('Display_LastTag', 'Letzter Tag', '', 31);
            $this->SetVariableCaption('Display_LastTag', 'Letzter Tag');
        }

        if (isset($states['lastcode'])) {
            $this->RegisterVariableString('Display_LastCode', 'Letzter Code', '', 32);
            $this->SetVariableCaption('Display_LastCode', 'Letzter Code');
        }

        if (isset($states['events']) && in_array($class, ['nfc', 'access'], true)) {
            $this->RegisterVariableString('Display_Events', 'Ereignisse', '', 40);
            $this->SetVariableCaption('Display_Events', 'Ereignisse');
        }
    }

    private function ApplyVisibilityPolicy(): void
    {
        $technicalIdents = [
            'ControlUUID', 'ActionUUID', 'ControlType', 'RoomName', 'CategoryName', 'GatewayIDText'
        ];

        foreach ($technicalIdents as $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($id !== false) {
                @IPS_SetHidden($id, true);
            }
        }

        $states = $this->DecodeJsonObject($this->ReadPropertyString('StatesJson'));
        foreach ($states as $stateName => $stateUuid) {
            $ident = 'State_' . $this->IdentFromString((string)$stateName);
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($id !== false) {
                @IPS_SetHidden($id, true);
            }
        }

        $hiddenDisplayIdents = $this->HiddenDisplayIdentsForDeviceClass();
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $object = IPS_GetObject($childId);
            $ident = (string)($object['ObjectIdent'] ?? '');
            if (str_starts_with($ident, 'Display_')) {
                @IPS_SetHidden($childId, in_array($ident, $hiddenDisplayIdents, true));
            }
        }
    }

    private function SetPresentationValueFromState(string $stateName, $rawValue): void
    {
        $stateNameLower = strtolower($stateName);
        $type = strtolower($this->ReadPropertyString('ControlType'));

        if ($stateNameLower === 'active') {
            if ($type === 'switch') {
                $this->SetValueIfExists('Display_Switch', $this->ToBool($rawValue));
            } elseif ($type === 'infoonlydigital') {
                $this->SetValueIfExists('Display_Status', $this->ToBool($rawValue));
            }
            return;
        }

        if ($stateNameLower === 'jlocked') {
            $this->SetValueIfExists('Display_Locked', $this->ToBool($rawValue));
            return;
        }

        if ($stateNameLower === 'lockedon') {
            $this->SetValueIfExists('Display_LockedOn', $this->ToBool($rawValue));
            return;
        }

        if ($stateNameLower === 'devicestate') {
            $this->SetValueIfExists('Display_DeviceState', (int)$rawValue);
            return;
        }

        if ($stateNameLower === 'mode') {
            $this->SetValueIfExists('Display_Mode', (int)$rawValue);
            return;
        }

        if ($stateNameLower === 'value') {
            if ($type === 'daytimer') {
                $this->SetValueIfExists('Display_DaytimerActive', $this->ToBool($rawValue));
            } else {
                $this->SetValueIfExists('Display_Value', (float)$rawValue);
            }
            return;
        }

        if ($stateNameLower === 'override') {
            $this->SetValueIfExists('Display_DaytimerOverride', $this->ToBool($rawValue));
            return;
        }

        if ($stateNameLower === 'lastuser') {
            $this->SetValueIfExists('Display_LastUser', (string)$rawValue);
            return;
        }

        if ($stateNameLower === 'lasttag') {
            $this->SetValueIfExists('Display_LastTag', (string)$rawValue);
            return;
        }

        if ($stateNameLower === 'lastcode') {
            $this->SetValueIfExists('Display_LastCode', (string)$rawValue);
            return;
        }

        if ($stateNameLower === 'events') {
            if (is_array($rawValue) || is_object($rawValue)) {
                $this->SetValueIfExists('Display_Events', json_encode($rawValue, JSON_UNESCAPED_UNICODE));
            } else {
                $this->SetValueIfExists('Display_Events', (string)$rawValue);
            }
        }
    }

    private function DetectDeviceClass(): string
    {
        $type = strtolower($this->ReadPropertyString('ControlType'));
        $name = strtolower($this->ReadPropertyString('ControlName'));
        $category = strtolower($this->ReadPropertyString('CategoryName'));
        $states = $this->DecodeJsonObject($this->ReadPropertyString('StatesJson'));

        if ($type === 'daytimer') {
            return 'daytimer';
        }
        if ($type === 'nfccodetouch') {
            return 'nfc';
        }
        if (str_contains($name, 'garage') || str_contains($name, 'tor') || str_contains($name, 'gartentor')) {
            return 'gate';
        }
        if (str_contains($name, 'tür') || str_contains($name, 'tuere') || str_contains($name, 'ture') || str_contains($category, 'zutritt')) {
            return 'access';
        }
        if (str_contains($name, 'alarm') || str_contains($category, 'alarm')) {
            return 'alarm';
        }
        if ($type === 'switch') {
            return 'switch';
        }
        if ($type === 'pushbutton') {
            return 'pushbutton';
        }
        if ($type === 'infoonlydigital') {
            return 'status';
        }
        if (isset($states['temperature']) || str_contains($category, 'temperatur')) {
            return 'temperature';
        }
        return 'generic';
    }

    private function ActionCaptionForDeviceClass(string $class, string $name): string
    {
        $nameLower = strtolower($name);
        if ($class === 'gate') {
            return 'Tor auslösen';
        }
        if ($class === 'alarm') {
            if (str_contains($nameLower, 'unscharf')) {
                return 'Unscharf schalten';
            }
            if (str_contains($nameLower, 'scharf')) {
                return 'Scharf schalten';
            }
            return 'Alarmaktion auslösen';
        }
        if ($class === 'access' || $class === 'nfc') {
            return 'Öffnen / Auslösen';
        }
        return 'Auslösen';
    }

    private function StatusCaptionForDeviceClass(string $class, string $name): string
    {
        $nameLower = strtolower($name);
        if ($class === 'gate') {
            return 'Torstatus';
        }
        if ($class === 'access' || str_contains($nameLower, 'tür') || str_contains($nameLower, 'tuere')) {
            return 'Türstatus';
        }
        if ($class === 'alarm') {
            return 'Alarmstatus';
        }
        if (str_contains($nameLower, 'strom')) {
            return 'Stromversorgung';
        }
        return 'Status';
    }

    private function HiddenDisplayIdentsForDeviceClass(): array
    {
        $class = $this->DetectDeviceClass();
        if ($class === 'daytimer') {
            return ['Display_Mode', 'Display_Value', 'Display_Events', 'Display_DeviceState'];
        }
        if (in_array($class, ['nfc', 'access'], true)) {
            return ['Display_Mode', 'Display_Value'];
        }
        if ($class === 'gate') {
            return ['Display_Mode', 'Display_Value', 'Display_DeviceState', 'Display_Events'];
        }
        return [];
    }

    private function SetVariableCaption(string $ident, string $caption): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id !== false) {
            @IPS_SetName($id, $caption);
        }
    }

    private function SetValueIfExists(string $ident, $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false) {
            return;
        }

        $variable = IPS_GetVariable($id);
        switch ((int)$variable['VariableType']) {
            case 0:
                SetValueBoolean($id, $this->ToBool($value));
                break;
            case 1:
                SetValueInteger($id, (int)$value);
                break;
            case 2:
                SetValueFloat($id, (float)$value);
                break;
            case 3:
                SetValueString($id, is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value);
                break;
        }
    }

    private function SetDefaultValueIfEmpty(string $ident, $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false) {
            return;
        }

        $this->SetValueIfExists($ident, $value);
    }

    private function ToBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((float)$value) != 0.0;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'on', 'ein', 'yes'], true);
    }

    private function SetTypedStateValue(string $ident, $rawValue): void
    {
        $variableId = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($variableId === false) {
            return;
        }

        $variable = IPS_GetVariable($variableId);
        $type = (int)$variable['VariableType'];

        switch ($type) {
            case 0:
                if (is_bool($rawValue)) {
                    $value = $rawValue;
                } elseif (is_numeric($rawValue)) {
                    $value = ((float)$rawValue) != 0.0;
                } else {
                    $value = in_array(strtolower((string)$rawValue), ['1', 'true', 'on', 'ein', 'yes'], true);
                }
                SetValueBoolean($variableId, $value);
                break;
            case 1:
                SetValueInteger($variableId, (int)$rawValue);
                break;
            case 2:
                SetValueFloat($variableId, (float)$rawValue);
                break;
            case 3:
                if (is_array($rawValue) || is_object($rawValue)) {
                    SetValueString($variableId, json_encode($rawValue, JSON_UNESCAPED_UNICODE));
                } else {
                    SetValueString($variableId, (string)$rawValue);
                }
                break;
        }
    }

    private function RegisterStateVariables(): void
    {
        $states = $this->DecodeJsonObject($this->ReadPropertyString('StatesJson'));
        $controlType = $this->ReadPropertyString('ControlType');
        $position = 100;

        foreach ($states as $stateName => $stateUuid) {
            $stateName = (string)$stateName;
            $stateUuid = (string)$stateUuid;
            $ident = 'State_' . $this->IdentFromString($stateName);
            $caption = $this->CaptionForState($stateName) . ' [' . $stateName . ']';
            $type = $this->VariableTypeForState($stateName, $controlType);

            if ($this->HasVariableWithIdent($ident)) {
                $existingId = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                if ($existingId !== false) {
                    @IPS_SetHidden($existingId, true);
                }
                $position += 10;
                continue;
            }

            switch ($type) {
                case 0:
                    $this->RegisterVariableBoolean($ident, $caption, '~Switch', $position);
                    if ($stateName === 'active' && in_array(strtolower($controlType), ['switch', 'pushbutton'], true)) {
                        $this->EnableAction($ident);
                    }
                    $this->SetValue($ident, false);
                    break;
                case 1:
                    $this->RegisterVariableInteger($ident, $caption, '', $position);
                    $this->SetValue($ident, 0);
                    break;
                case 2:
                    $this->RegisterVariableFloat($ident, $caption, $this->ProfileForFloatState($stateName), $position);
                    $this->SetValue($ident, 0.0);
                    break;
                default:
                    $this->RegisterVariableString($ident, $caption, '', $position);
                    $this->SetValue($ident, $stateUuid);
                    break;
            }

            $createdId = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($createdId !== false) {
                @IPS_SetHidden($createdId, true);
            }

            $position += 10;
        }
    }

    private function HasVariableWithIdent(string $ident): bool
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return $id !== false;
    }

    private function DecodeJsonObject(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }

    private function VariableTypeForState(string $stateName, string $controlType): int
    {
        $name = strtolower($stateName);
        $boolStates = [
            'active', 'jlocked', 'lockedon', 'resetactive', 'needsactivation', 'opened', 'closed',
            'online', 'offline', 'certificatevalid', 'hasinternet', 'changed'
        ];
        if (in_array($name, $boolStates, true)) {
            return 0;
        }

        $integerStates = ['mode', 'devicestate', 'keypadauthtype', 'lastid'];
        if (in_array($name, $integerStates, true)) {
            return 1;
        }

        $floatStates = ['value', 'position', 'temperature', 'humidity', 'brightness', 'speed'];
        if (in_array($name, $floatStates, true)) {
            return 2;
        }

        return 3;
    }

    private function ProfileForFloatState(string $stateName): string
    {
        $name = strtolower($stateName);
        if (str_contains($name, 'temperature')) {
            return '~Temperature';
        }
        if (str_contains($name, 'humidity')) {
            return '~Humidity';
        }
        return '';
    }

    private function CaptionForState(string $stateName): string
    {
        $labels = [
            'active' => 'Aktiv',
            'jLocked' => 'Gesperrt',
            'lockedOn' => 'Gesperrt ein',
            'mode' => 'Modus',
            'value' => 'Wert',
            'position' => 'Position',
            'temperature' => 'Temperatur',
            'humidity' => 'Luftfeuchte',
            'lastuser' => 'Letzter Benutzer',
            'lasttag' => 'Letzter Tag',
            'lastcode' => 'Letzter Code',
            'deviceState' => 'Gerätestatus',
            'events' => 'Ereignisse',
            'override' => 'Override'
        ];

        return $labels[$stateName] ?? ucfirst($stateName);
    }

    private function IdentFromString(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value);
        $value = trim((string)$value, '_');
        if ($value === '') {
            $value = 'item';
        }
        if (preg_match('/^[0-9]/', $value) === 1) {
            $value = 'n_' . $value;
        }
        return $value;
    }
}
