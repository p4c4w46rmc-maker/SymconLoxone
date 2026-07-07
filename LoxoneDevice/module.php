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

        $this->RegisterStateVariables();
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
        if ((string)$ident === 'State_active') {
            $controlType = strtolower($this->ReadPropertyString('ControlType'));
            if ($controlType === 'switch') {
                if ((bool)$value) {
                    $this->SwitchOn();
                } else {
                    $this->SwitchOff();
                }
                $this->SetValue('State_active', (bool)$value);
                return;
            }

            if ($controlType === 'pushbutton') {
                $this->Press();
                return;
            }
        }

        throw new Exception('Keine Aktion für ' . (string)$ident . ' verfügbar.');
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
            'events' => 'Ereignisse'
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
