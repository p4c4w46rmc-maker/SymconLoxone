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
