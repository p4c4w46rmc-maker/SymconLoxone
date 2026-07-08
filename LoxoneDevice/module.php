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
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $name = trim($this->ReadPropertyString('ControlName'));
        if ($name !== '') {
            IPS_SetName($this->InstanceID, $name);
        }

        $this->CreateBaseCategories();
        $this->RegisterTechnicalVariables();
        $this->RegisterPresentationVariables();
        $this->RegisterStateVariables();
        $this->ApplyVisibilityPolicy();

        $this->SetSummary($this->HumanDeviceClassLabel($this->DetectDeviceClass()) . ' / ' . $this->ReadPropertyString('ControlType'));
    }

    public function RefreshMetadata()
    {
        $this->ApplyChanges();
        $states = $this->DecodeJsonObject($this->ReadPropertyString('StatesJson'));
        return "Metadaten aktualisiert\n" .
            "Control: " . $this->ReadPropertyString('ControlName') . "\n" .
            "Typ: " . $this->ReadPropertyString('ControlType') . "\n" .
            "Klasse: " . $this->HumanDeviceClassLabel($this->DetectDeviceClass()) . "\n" .
            "Raum: " . $this->ReadPropertyString('RoomName') . "\n" .
            "Kategorie: " . $this->ReadPropertyString('CategoryName') . "\n" .
            "States: " . count($states);
    }

    public function RefreshStateValues()
    {
        $gatewayId = $this->ReadPropertyInteger('GatewayID');
        if ($gatewayId <= 0 || !IPS_InstanceExists($gatewayId)) {
            return 'Kein gültiges Gateway hinterlegt.';
        }

        $states = $this->DecodeJsonObject($this->ReadPropertyString('StatesJson'));
        $updated = 0;
        $errors = [];

        foreach ($states as $stateName => $stateUuid) {
            $stateName = (string)$stateName;
            $stateUuid = (string)$stateUuid;
            $ident = 'State_' . $this->IdentFromString($stateName);
            $variableId = $this->FindObjectIDByIdent($ident);
            if ($variableId === false) {
                continue;
            }

            try {
                $rawValue = LOX_GetStateValue($gatewayId, $stateUuid);
                $this->SetTypedStateValueByID((int)$variableId, $rawValue);
                $this->SetPresentationValueFromState($stateName, $rawValue);
                $updated++;
            } catch (Throwable $e) {
                $errors[] = $stateName . ': ' . $e->getMessage();
            }
        }

        $text = "Statuswerte aktualisiert\n" .
            "Control: " . $this->ReadPropertyString('ControlName') . "\n" .
            "Aktualisiert: " . $updated;

        if (count($errors) > 0) {
            $text .= "\nFehler: " . count($errors) . "\n" . implode("\n", array_slice($errors, 0, 5));
        }

        return $text;
    }

    public function RequestAction($ident, $value)
    {
        $ident = (string)$ident;
        $controlType = strtolower($this->ReadPropertyString('ControlType'));
        $class = $this->DetectDeviceClass();

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

        if ($ident === 'Display_GatePulse') {
            // Torsignale sind Impulse. Der WebFront-Schalter wird nach dem Senden
            // direkt wieder zurückgesetzt; den echten Zustand liefert Loxone über
            // den Live-Status.
            $this->Press();
            $this->SetValueIfExists('Display_GatePulse', false);
            return;
        }

        if ($ident === 'Display_AlarmAction') {
            // Achtung: Alarmaktionen werden nur für importierte Pushbuttons ausgelöst.
            // Eine zusätzliche Sicherheitsabfrage muss auf WebFront-/Skript-Ebene
            // erfolgen, falls gewünscht.
            $this->Press();
            $this->SetValueIfExists('Display_AlarmAction', false);
            return;
        }

        if ($ident === 'Display_AccessOpen') {
            $this->Press();
            $this->SetValueIfExists('Display_AccessOpen', false);
            return;
        }

        throw new Exception('Keine Aktion für ' . $ident . ' verfügbar. Klasse: ' . $class);
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
        $class = $this->DetectDeviceClass();

        try {
            if ($type === 'switch') {
                $result = $this->Toggle();
                $command = 'pulse';
            } elseif ($type === 'pushbutton') {
                $result = $this->Press();
                $command = 'pulse';
            } else {
                return "Für diesen Control-Typ ist kein Standardbefehl hinterlegt.\n" .
                    "Typ: " . $this->ReadPropertyString('ControlType') . "\n" .
                    "Klasse: " . $this->HumanDeviceClassLabel($class);
            }

            return "Befehl gesendet\n" .
                "Control: " . $this->ReadPropertyString('ControlName') . "\n" .
                "Typ: " . $this->ReadPropertyString('ControlType') . "\n" .
                "Klasse: " . $this->HumanDeviceClassLabel($class) . "\n" .
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

    public function ActionDiagnostics()
    {
        $idents = [
            'Display_Switch',
            'Display_Press',
            'Display_GatePulse',
            'Display_AlarmAction',
            'Display_AccessOpen'
        ];

        $lines = [];
        $lines[] = 'Action-Diagnose';
        $lines[] = 'Control: ' . $this->ReadPropertyString('ControlName');
        $lines[] = 'Typ: ' . $this->ReadPropertyString('ControlType');
        $lines[] = 'Klasse: ' . $this->HumanDeviceClassLabel($this->DetectDeviceClass());
        $lines[] = 'GatewayID: ' . (string)$this->ReadPropertyInteger('GatewayID');
        $lines[] = 'ActionUUID: ' . $this->ReadPropertyString('ActionUUID');
        $lines[] = '';

        foreach ($idents as $ident) {
            $id = $this->FindObjectIDByIdent($ident);
            if ($id === false) {
                continue;
            }
            $var = IPS_GetVariable((int)$id);
            $action = (int)($var['VariableCustomAction'] ?? 0);
            $lines[] = $ident . ' → VariableID ' . (string)$id . ', CustomAction ' . (string)$action;
        }

        return implode("\n", $lines);
    }

    private function CreateBaseCategories(): void
    {
        $this->EnsureCategory('Cat_Control', 'Bedienung', 10, 'Execute');
        $this->EnsureCategory('Cat_Status', 'Status', 20, 'Information');
        $this->EnsureCategory('Cat_Info', 'Informationen', 30, 'Information');
        $this->EnsureCategory('Cat_Technical', 'Technik', 900, 'Database');
    }

    private function RegisterTechnicalVariables(): void
    {
        $technicalParent = $this->EnsureCategory('Cat_Technical', 'Technik', 900, 'Database');

        $this->EnsureVariableString('ControlUUID', 'Control UUID', '', 10, $technicalParent, false);
        $this->EnsureVariableString('ActionUUID', 'Action UUID', '', 20, $technicalParent, false);
        $this->EnsureVariableString('ControlType', 'Control Typ', '', 30, $technicalParent, false);
        $this->EnsureVariableString('RoomName', 'Raum', '', 40, $technicalParent, false);
        $this->EnsureVariableString('CategoryName', 'Kategorie', '', 50, $technicalParent, false);
        $this->EnsureVariableString('GatewayIDText', 'Gateway Instanz', '', 60, $technicalParent, false);
        $this->EnsureVariableString('DeviceClass', 'Geräteklasse', '', 70, $technicalParent, false);

        $this->SetValueIfExists('ControlUUID', $this->ReadPropertyString('ControlUUID'));
        $this->SetValueIfExists('ActionUUID', $this->ReadPropertyString('ActionUUID'));
        $this->SetValueIfExists('ControlType', $this->ReadPropertyString('ControlType'));
        $this->SetValueIfExists('RoomName', $this->ReadPropertyString('RoomName'));
        $this->SetValueIfExists('CategoryName', $this->ReadPropertyString('CategoryName'));
        $this->SetValueIfExists('GatewayIDText', (string)$this->ReadPropertyInteger('GatewayID'));
        $this->SetValueIfExists('DeviceClass', $this->HumanDeviceClassLabel($this->DetectDeviceClass()));
    }

    private function RegisterPresentationVariables(): void
    {
        $type = strtolower($this->ReadPropertyString('ControlType'));
        $name = $this->ReadPropertyString('ControlName');
        $states = $this->DecodeJsonObject($this->ReadPropertyString('StatesJson'));
        $class = $this->DetectDeviceClass();

        $controlParent = $this->EnsureCategory('Cat_Control', 'Bedienung', 10, 'Execute');
        $statusParent = $this->EnsureCategory('Cat_Status', 'Status', 20, 'Information');
        $infoParent = $this->EnsureCategory('Cat_Info', 'Informationen', 30, 'Information');

        if ($type === 'switch' && isset($states['active'])) {
            $caption = $class === 'gate' ? 'Tor schalten' : 'Schalter';
            $this->EnsureVariableBoolean('Display_Switch', $caption, '~Switch', 1, $controlParent, true);
        }

        if ($type === 'pushbutton') {
            if ($class === 'gate') {
                $this->EnsureVariableBoolean('Display_GatePulse', 'Tor auslösen', '~Switch', 1, $controlParent, true);
                $this->SetValueIfExists('Display_GatePulse', false);
            } elseif ($class === 'alarm') {
                $this->EnsureVariableBoolean('Display_AlarmAction', $this->ActionCaptionForDeviceClass($class, $name), '~Switch', 1, $controlParent, true);
                $this->SetValueIfExists('Display_AlarmAction', false);
            } elseif ($class === 'access' || $class === 'nfc') {
                $this->EnsureVariableBoolean('Display_AccessOpen', 'Öffnen / Auslösen', '~Switch', 1, $controlParent, true);
                $this->SetValueIfExists('Display_AccessOpen', false);
            } else {
                $this->EnsureVariableBoolean('Display_Press', $this->ActionCaptionForDeviceClass($class, $name), '~Switch', 1, $controlParent, true);
                $this->SetValueIfExists('Display_Press', false);
            }
        }

        if ($type === 'infoonlydigital' && isset($states['active'])) {
            $this->EnsureVariableBoolean('Display_Status', $this->StatusCaptionForDeviceClass($class, $name), '~Switch', 1, $statusParent, false);
        }

        if ($type === 'daytimer') {
            if (isset($states['value'])) {
                $this->EnsureVariableBoolean('Display_DaytimerActive', 'Aktiv', '~Switch', 10, $statusParent, false);
            }
            if (isset($states['override'])) {
                $this->EnsureVariableBoolean('Display_DaytimerOverride', 'Override', '~Switch', 20, $controlParent, false);
            }
        }

        if (isset($states['jLocked'])) {
            $caption = ($class === 'nfc' || $class === 'access') ? 'Bedienung gesperrt' : 'Gesperrt';
            $this->EnsureVariableBoolean('Display_Locked', $caption, '~Switch', 10, $statusParent, false);
        }

        if (isset($states['lockedOn'])) {
            $this->EnsureVariableBoolean('Display_LockedOn', 'Gesperrt ein', '~Switch', 11, $statusParent, false);
        }

        if (isset($states['deviceState']) && in_array($class, ['nfc', 'access'], true)) {
            $this->EnsureVariableInteger('Display_DeviceState', 'Gerätestatus', '', 20, $statusParent, false);
        }

        if (isset($states['mode']) && !in_array($class, ['daytimer', 'nfc', 'gate', 'access'], true)) {
            $this->EnsureVariableInteger('Display_Mode', 'Modus', '', 21, $statusParent, false);
        }

        if (isset($states['value']) && !in_array($class, ['daytimer'], true)) {
            $this->EnsureVariableFloat('Display_Value', 'Wert', '', 22, $statusParent, false);
        }

        if (isset($states['lastuser'])) {
            $this->EnsureVariableString('Display_LastUser', 'Letzter Benutzer', '', 30, $infoParent, false);
        }
        if (isset($states['lasttag'])) {
            $this->EnsureVariableString('Display_LastTag', 'Letzter Tag', '', 31, $infoParent, false);
        }
        if (isset($states['lastcode'])) {
            $this->EnsureVariableString('Display_LastCode', 'Letzter Code', '', 32, $infoParent, false);
        }
        if (isset($states['events']) && in_array($class, ['nfc', 'access'], true)) {
            $this->EnsureVariableString('Display_Events', 'Ereignisse', '', 40, $infoParent, false);
        }
    }

    private function RegisterStateVariables(): void
    {
        $states = $this->DecodeJsonObject($this->ReadPropertyString('StatesJson'));
        $controlType = $this->ReadPropertyString('ControlType');
        $parentId = $this->EnsureCategory('Cat_Technical', 'Technik', 900, 'Database');
        $position = 100;

        foreach ($states as $stateName => $stateUuid) {
            $stateName = (string)$stateName;
            $stateUuid = (string)$stateUuid;
            $ident = 'State_' . $this->IdentFromString($stateName);
            $caption = $this->CaptionForState($stateName) . ' [' . $stateName . ']';
            $type = $this->VariableTypeForState($stateName, $controlType);

            switch ($type) {
                case 0:
                    $this->EnsureVariableBoolean($ident, $caption, '~Switch', $position, $parentId, false);
                    $this->SetValueIfExists($ident, false);
                    break;
                case 1:
                    $this->EnsureVariableInteger($ident, $caption, '', $position, $parentId, false);
                    $this->SetValueIfExists($ident, 0);
                    break;
                case 2:
                    $this->EnsureVariableFloat($ident, $caption, $this->ProfileForFloatState($stateName), $position, $parentId, false);
                    $this->SetValueIfExists($ident, 0.0);
                    break;
                default:
                    $this->EnsureVariableString($ident, $caption, '', $position, $parentId, false);
                    $this->SetValueIfExists($ident, $stateUuid);
                    break;
            }

            $id = $this->FindObjectIDByIdent($ident);
            if ($id !== false) {
                @IPS_SetHidden((int)$id, true);
            }
            $position += 10;
        }
    }

    private function ApplyVisibilityPolicy(): void
    {
        $hideIdents = [
            'ControlUUID', 'ActionUUID', 'ControlType', 'RoomName', 'CategoryName', 'GatewayIDText', 'DeviceClass'
        ];
        foreach ($hideIdents as $ident) {
            $id = $this->FindObjectIDByIdent($ident);
            if ($id !== false) {
                @IPS_SetHidden((int)$id, true);
            }
        }

        $technicalId = $this->FindObjectIDByIdent('Cat_Technical');
        if ($technicalId !== false) {
            @IPS_SetHidden((int)$technicalId, true);
        }

        $hiddenDisplayIdents = $this->HiddenDisplayIdentsForDeviceClass();
        foreach ($hiddenDisplayIdents as $ident) {
            $id = $this->FindObjectIDByIdent($ident);
            if ($id !== false) {
                @IPS_SetHidden((int)$id, true);
            }
        }
    }

    private function SetPresentationValueFromState(string $stateName, $rawValue): void
    {
        $stateNameLower = strtolower($stateName);
        $type = strtolower($this->ReadPropertyString('ControlType'));
        $class = $this->DetectDeviceClass();

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
            $this->SetValueIfExists('Display_Events', $rawValue);
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

    private function HumanDeviceClassLabel(string $class): string
    {
        $labels = [
            'daytimer' => 'Schaltuhr',
            'nfc' => 'NFC Code Touch',
            'gate' => 'Tor',
            'access' => 'Zutritt',
            'alarm' => 'Alarm',
            'switch' => 'Schalter',
            'pushbutton' => 'Taster',
            'status' => 'Status',
            'temperature' => 'Temperatur',
            'generic' => 'Allgemein'
        ];
        return $labels[$class] ?? $class;
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

    private function EnsureCategory(string $ident, string $name, int $position, string $icon = ''): int
    {
        $id = $this->FindDirectObjectIDByIdent($ident);
        if ($id === false) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $ident);
        }
        IPS_SetName((int)$id, $name);
        IPS_SetPosition((int)$id, $position);
        if ($icon !== '') {
            @IPS_SetIcon((int)$id, $icon);
        }
        return (int)$id;
    }

    private function EnsureVariableBoolean(string $ident, string $name, string $profile, int $position, int $parentId, bool $enableAction): int
    {
        return $this->EnsureVariable($ident, $name, 0, $profile, $position, $parentId, $enableAction);
    }

    private function EnsureVariableInteger(string $ident, string $name, string $profile, int $position, int $parentId, bool $enableAction): int
    {
        return $this->EnsureVariable($ident, $name, 1, $profile, $position, $parentId, $enableAction);
    }

    private function EnsureVariableFloat(string $ident, string $name, string $profile, int $position, int $parentId, bool $enableAction): int
    {
        return $this->EnsureVariable($ident, $name, 2, $profile, $position, $parentId, $enableAction);
    }

    private function EnsureVariableString(string $ident, string $name, string $profile, int $position, int $parentId, bool $enableAction): int
    {
        return $this->EnsureVariable($ident, $name, 3, $profile, $position, $parentId, $enableAction);
    }

    private function EnsureVariable(string $ident, string $name, int $type, string $profile, int $position, int $parentId, bool $enableAction): int
    {
        $id = $this->FindObjectIDByIdent($ident);
        if ($id === false) {
            switch ($type) {
                case 0:
                    $this->RegisterVariableBoolean($ident, $name, $profile, $position);
                    break;
                case 1:
                    $this->RegisterVariableInteger($ident, $name, $profile, $position);
                    break;
                case 2:
                    $this->RegisterVariableFloat($ident, $name, $profile, $position);
                    break;
                default:
                    $this->RegisterVariableString($ident, $name, $profile, $position);
                    break;
            }
            $id = $this->FindDirectObjectIDByIdent($ident);
        }

        if ($id === false) {
            throw new RuntimeException('Variable konnte nicht erstellt werden: ' . $ident);
        }

        IPS_SetName((int)$id, $name);
        IPS_SetPosition((int)$id, $position);
        if ($parentId > 0 && IPS_ObjectExists($parentId)) {
            IPS_SetParent((int)$id, $parentId);
        }
        if ($profile !== '') {
            @IPS_SetVariableCustomProfile((int)$id, $profile);
        }

        // Sprint 16: Bedienvariablen liegen inzwischen in Unterkategorien
        // (Bedienung / Status / Informationen). IP-Symcons EnableAction($ident)
        // arbeitet bei verschobenen Variablen nicht zuverlässig, weil die Variable
        // nicht mehr direkt unter der Instanz liegt. Deshalb setzen wir die
        // CustomAction direkt über die VariableID auf diese Geräteinstanz.
        if ($enableAction) {
            @IPS_SetVariableCustomAction((int)$id, $this->InstanceID);
        } else {
            @IPS_SetVariableCustomAction((int)$id, 0);
        }

        @IPS_SetHidden((int)$id, false);
        return (int)$id;
    }

    private function FindDirectObjectIDByIdent(string $ident)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return $id === false ? false : (int)$id;
    }

    private function FindObjectIDByIdent(string $ident)
    {
        $direct = $this->FindDirectObjectIDByIdent($ident);
        if ($direct !== false) {
            return $direct;
        }
        return $this->FindObjectIDByIdentRecursive($this->InstanceID, $ident);
    }

    private function FindObjectIDByIdentRecursive(int $parentId, string $ident)
    {
        foreach (IPS_GetChildrenIDs($parentId) as $childId) {
            $object = IPS_GetObject($childId);
            if ((string)($object['ObjectIdent'] ?? '') === $ident) {
                return (int)$childId;
            }
            if ((int)$object['ObjectType'] === 0) {
                $found = $this->FindObjectIDByIdentRecursive((int)$childId, $ident);
                if ($found !== false) {
                    return $found;
                }
            }
        }
        return false;
    }

    private function SetValueIfExists(string $ident, $value): void
    {
        $id = $this->FindObjectIDByIdent($ident);
        if ($id === false) {
            return;
        }
        $variable = IPS_GetVariable((int)$id);
        switch ((int)$variable['VariableType']) {
            case 0:
                SetValueBoolean((int)$id, $this->ToBool($value));
                break;
            case 1:
                SetValueInteger((int)$id, (int)$value);
                break;
            case 2:
                SetValueFloat((int)$id, (float)$value);
                break;
            case 3:
                SetValueString((int)$id, is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value);
                break;
        }
    }

    private function SetTypedStateValueByID(int $variableId, $rawValue): void
    {
        $variable = IPS_GetVariable($variableId);
        switch ((int)$variable['VariableType']) {
            case 0:
                SetValueBoolean($variableId, $this->ToBool($rawValue));
                break;
            case 1:
                SetValueInteger($variableId, (int)$rawValue);
                break;
            case 2:
                SetValueFloat($variableId, (float)$rawValue);
                break;
            case 3:
                SetValueString($variableId, is_array($rawValue) || is_object($rawValue) ? json_encode($rawValue, JSON_UNESCAPED_UNICODE) : (string)$rawValue);
                break;
        }
    }

    private function DecodeJsonObject(string $json): array
    {
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function VariableTypeForState(string $stateName, string $controlType): int
    {
        $name = strtolower($stateName);
        $boolStates = [
            'active', 'jlocked', 'lockedon', 'resetactive', 'needsactivation', 'opened',
            'closed', 'online', 'offline', 'certificatevalid', 'hasinternet', 'changed'
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
        if (str_contains($name, 'position') || str_contains($name, 'brightness')) {
            return '~Intensity.100';
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
