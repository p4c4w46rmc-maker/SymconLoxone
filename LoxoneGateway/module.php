<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/LoxoneAPI.php';

class LoxoneGateway extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '192.168.178.230');
        $this->RegisterPropertyInteger('Port', 80);
        $this->RegisterPropertyBoolean('UseHttps', false);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('PollingInterval', 0);

        $this->RegisterTimer('RefreshStates', 0, 'LOX_RefreshAllDeviceStates($_IPS["TARGET"]);');

        $this->RegisterVariableString('Version', 'Miniserver Version', '', 10);
        $this->RegisterVariableString('ProjectName', 'Projektname', '', 20);
        $this->RegisterVariableString('SerialNumber', 'Seriennummer', '', 30);
        $this->RegisterVariableString('CurrentUser', 'Benutzer', '', 40);
        $this->RegisterVariableInteger('ImportedControls', 'Importierte Controls', '', 50);
        $this->RegisterVariableInteger('ImportedStates', 'Importierte States', '', 60);
        $this->RegisterVariableInteger('ImportedDevices', 'Erzeugte Geräte-Instanzen', '', 70);
        $this->RegisterVariableInteger('StateIndexSize', 'State-Index Einträge', '', 80);
        $this->RegisterVariableInteger('ReadableStateIndexSize', 'HTTP-lesbare State-Einträge', '', 85);
        $this->RegisterVariableString('LiveEngineStatus', 'LiveEngine Status', '', 90);
        $this->RegisterVariableString('LastLiveUpdate', 'Letztes Live-Update', '', 100);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetStatus(201);
            $this->SetSummary('Kein Host konfiguriert');
            return;
        }

        $scheme = $this->ReadPropertyBoolean('UseHttps') ? 'https' : 'http';
        $port = $this->ReadPropertyInteger('Port');
        $this->SetSummary(sprintf('%s://%s:%d', $scheme, $host, $port));
        $interval = $this->ReadPropertyInteger('PollingInterval');
        $this->SetTimerInterval('RefreshStates', max(0, $interval) * 1000);

        $this->SetStatus(102);
    }

    public function TestConnection()
    {
        try {
            $api = $this->CreateApi();
            $version = $api->getVersion();

            if (!isset($version['LL']['Code']) || (string) $version['LL']['Code'] !== '200') {
                return "Verbindung fehlgeschlagen:\n" . json_encode($version, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            $value = (string) ($version['LL']['value'] ?? 'unbekannt');
            $this->SetValue('Version', $value);

            return "Verbindung OK\nMiniserver Version: " . $value;
        } catch (Throwable $e) {
            return "Fehler beim Verbindungstest:\n" . $e->getMessage();
        }
    }

    public function ReadMiniserver()
    {
        try {
            $data = $this->CreateApi()->getLoxApp3();
            $msInfo = $data['msInfo'] ?? [];

            $projectName = (string) ($msInfo['projectName'] ?? '');
            $serialNumber = (string) ($msInfo['serialNr'] ?? '');
            $currentUser = (string) ($msInfo['currentUser']['name'] ?? '');

            $this->SetValue('ProjectName', $projectName);
            $this->SetValue('SerialNumber', $serialNumber);
            $this->SetValue('CurrentUser', $currentUser);

            $rooms = $this->CountArray($data, 'rooms');
            $cats = $this->CountArray($data, 'cats');
            $controls = $this->CountArray($data, 'controls');

            return "Miniserver ausgelesen\n" .
                "Projekt: " . $projectName . "\n" .
                "Seriennummer: " . $serialNumber . "\n" .
                "Benutzer: " . $currentUser . "\n" .
                "Räume: " . $rooms . "\n" .
                "Kategorien: " . $cats . "\n" .
                "Controls: " . $controls;
        } catch (Throwable $e) {
            return "Fehler beim Auslesen:\n" . $e->getMessage();
        }
    }

    public function ImportObjectTree()
    {
        return $this->ImportControls();
    }

    public function ImportControls()
    {
        try {
            $data = $this->CreateApi()->getLoxApp3();
            $rooms = is_array($data['rooms'] ?? null) ? $data['rooms'] : [];
            $cats = is_array($data['cats'] ?? null) ? $data['cats'] : [];
            $controls = is_array($data['controls'] ?? null) ? $data['controls'] : [];

            $roomNames = $this->BuildNameMap($rooms);
            $catNames = $this->BuildNameMap($cats);

            $rootId = $this->GetOrCreateCategory($this->InstanceID, 'Loxone', 'loxone_root', 100);
            $roomsRootId = $this->GetOrCreateCategory($rootId, 'Räume', 'rooms', 10);
            $catsRootId = $this->GetOrCreateCategory($rootId, 'Kategorien', 'categories', 20);
            $controlsRootId = $this->GetOrCreateCategory($rootId, 'Controls', 'controls', 30);

            foreach ($rooms as $uuid => $room) {
                $name = (string)($room['name'] ?? $uuid);
                $this->GetOrCreateCategory($roomsRootId, $name, 'room_' . $this->IdentFromUuid((string)$uuid));
            }

            foreach ($cats as $uuid => $cat) {
                $name = (string)($cat['name'] ?? $uuid);
                $this->GetOrCreateCategory($catsRootId, $name, 'cat_' . $this->IdentFromUuid((string)$uuid));
            }

            $importedControls = 0;
            $importedStates = 0;
            $typeCounter = [];

            foreach ($controls as $uuid => $control) {
                if (!is_array($control)) {
                    continue;
                }

                $controlName = (string)($control['name'] ?? $uuid);
                $controlType = (string)($control['type'] ?? 'Unknown');
                $roomUuid = (string)($control['room'] ?? '');
                $catUuid = (string)($control['cat'] ?? '');

                $roomName = $roomNames[$roomUuid] ?? 'Nicht zugeordnet';
                $catName = $catNames[$catUuid] ?? 'Nicht zugeordnet';

                $roomId = $this->GetOrCreateCategory($controlsRootId, $roomName, 'controls_room_' . $this->IdentFromUuidOrString($roomUuid, $roomName));
                $catId = $this->GetOrCreateCategory($roomId, $catName, 'controls_cat_' . $this->IdentFromUuidOrString($catUuid, $catName));
                $controlId = $this->GetOrCreateCategory($catId, $controlName, 'control_' . $this->IdentFromUuid((string)$uuid));

                IPS_SetIcon($controlId, $this->IconForControlType($controlType));

                $metaId = $this->GetOrCreateCategory($controlId, 'Metadaten', 'metadata', 10);
                $this->CreateOrUpdateStringVariable($metaId, 'UUID', 'uuid', (string)$uuid, 10);
                $this->CreateOrUpdateStringVariable($metaId, 'UUID Action', 'uuid_action', (string)($control['uuidAction'] ?? $uuid), 20);
                $this->CreateOrUpdateStringVariable($metaId, 'Typ', 'type', $controlType, 30);
                $this->CreateOrUpdateStringVariable($metaId, 'Raum', 'room', $roomName, 40);
                $this->CreateOrUpdateStringVariable($metaId, 'Kategorie', 'category', $catName, 50);
                $this->CreateOrUpdateIntegerVariable($metaId, 'Restrictions', 'restrictions', (int)($control['restrictions'] ?? 0), 60);

                $statesId = $this->GetOrCreateCategory($controlId, 'States', 'states', 20);
                foreach (($control['states'] ?? []) as $stateName => $stateUuid) {
                    $this->CreateTypedStateVariable(
                        $statesId,
                        (string)$stateName,
                        (string)$stateUuid,
                        $controlType,
                        10 + $importedStates
                    );
                    $importedStates++;
                }

                $typeCounter[$controlType] = ($typeCounter[$controlType] ?? 0) + 1;
                $importedControls++;
            }

            $this->SetValue('ImportedControls', $importedControls);
            $this->SetValue('ImportedStates', $importedStates);

            ksort($typeCounter);
            $types = [];
            foreach ($typeCounter as $type => $count) {
                $types[] = $type . ': ' . $count;
            }

            return "Controls importiert / aktualisiert\n" .
                "Räume: " . count($rooms) . "\n" .
                "Kategorien: " . count($cats) . "\n" .
                "Controls: " . $importedControls . "\n" .
                "States: " . $importedStates . "\n\n" .
                "Control-Typen:\n" . implode("\n", $types);
        } catch (Throwable $e) {
            return "Fehler beim Import:\n" . $e->getMessage();
        }
    }



    public function ImportDeviceInstances()
    {
        try {
            $data = $this->CreateApi()->getLoxApp3();
            $rooms = is_array($data['rooms'] ?? null) ? $data['rooms'] : [];
            $cats = is_array($data['cats'] ?? null) ? $data['cats'] : [];
            $controls = is_array($data['controls'] ?? null) ? $data['controls'] : [];

            $roomNames = $this->BuildNameMap($rooms);
            $catNames = $this->BuildNameMap($cats);

            $rootId = $this->GetOrCreateCategory($this->InstanceID, 'Loxone', 'loxone_root', 100);
            $devicesRootId = $this->GetOrCreateCategory($rootId, 'Geräte', 'devices', 40);

            $createdOrUpdated = 0;
            $typeCounter = [];
            $deviceModuleGuid = '{7A18B2F4-2FA0-4D78-9E19-6D445A182C10}';

            foreach ($controls as $uuid => $control) {
                if (!is_array($control)) {
                    continue;
                }

                $controlName = (string)($control['name'] ?? $uuid);
                $controlType = (string)($control['type'] ?? 'Unknown');
                $roomUuid = (string)($control['room'] ?? '');
                $catUuid = (string)($control['cat'] ?? '');
                $roomName = $roomNames[$roomUuid] ?? 'Nicht zugeordnet';
                $catName = $catNames[$catUuid] ?? 'Nicht zugeordnet';

                $roomId = $this->GetOrCreateCategory($devicesRootId, $roomName, 'device_room_' . $this->IdentFromUuidOrString($roomUuid, $roomName));
                $catId = $this->GetOrCreateCategory($roomId, $catName, 'device_cat_' . $this->IdentFromUuidOrString($catUuid, $catName));
                $ident = 'device_' . $this->IdentFromUuid((string)$uuid);

                $instanceId = @IPS_GetObjectIDByIdent($ident, $catId);
                if ($instanceId === false) {
                    $instanceId = IPS_CreateInstance($deviceModuleGuid);
                    IPS_SetParent($instanceId, $catId);
                    IPS_SetIdent($instanceId, $ident);
                }

                IPS_SetName($instanceId, $controlName);
                IPS_SetIcon($instanceId, $this->IconForControlType($controlType));

                IPS_SetProperty($instanceId, 'GatewayID', $this->InstanceID);
                IPS_SetProperty($instanceId, 'ControlUUID', (string)$uuid);
                IPS_SetProperty($instanceId, 'ActionUUID', (string)($control['uuidAction'] ?? $uuid));
                IPS_SetProperty($instanceId, 'ControlName', $controlName);
                IPS_SetProperty($instanceId, 'ControlType', $controlType);
                IPS_SetProperty($instanceId, 'RoomName', $roomName);
                IPS_SetProperty($instanceId, 'CategoryName', $catName);
                IPS_SetProperty($instanceId, 'StatesJson', json_encode($control['states'] ?? [], JSON_UNESCAPED_UNICODE));
                IPS_SetProperty($instanceId, 'DetailsJson', json_encode($control['details'] ?? [], JSON_UNESCAPED_UNICODE));
                IPS_ApplyChanges($instanceId);

                $typeCounter[$controlType] = ($typeCounter[$controlType] ?? 0) + 1;
                $createdOrUpdated++;
            }

            $this->SetValue('ImportedDevices', $createdOrUpdated);

            ksort($typeCounter);
            $types = [];
            foreach ($typeCounter as $type => $count) {
                $types[] = $type . ': ' . $count;
            }

            return "Geräte-Instanzen erzeugt / aktualisiert\n" .
                "Geräte: " . $createdOrUpdated . "\n\n" .
                "Control-Typen:\n" . implode("\n", $types);
        } catch (Throwable $e) {
            return "Fehler beim Erzeugen der Geräte-Instanzen:\n" . $e->getMessage();
        }
    }

    private function CreateTypedStateVariable(int $parentId, string $stateName, string $stateUuid, string $controlType, int $position): int
    {
        $ident = 'state_' . $this->IdentFromString($stateName);
        $type = $this->VariableTypeForState($stateName, $controlType);
        $profile = $this->ProfileForState($stateName, $type);
        $caption = $stateName . ' (' . $stateUuid . ')';

        $id = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($id === false) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $parentId);
            IPS_SetIdent($id, $ident);
        }

        IPS_SetName($id, $caption);
        IPS_SetPosition($id, $position);
        if ($profile !== '') {
            @IPS_SetVariableCustomProfile($id, $profile);
        }

        // Default value only at import time. Real values will be provided by WebSocket in the next sprint.
        switch ($type) {
            case 0:
                SetValueBoolean($id, false);
                break;
            case 1:
                SetValueInteger($id, 0);
                break;
            case 2:
                SetValueFloat($id, 0.0);
                break;
            default:
                SetValueString($id, $stateUuid);
                break;
        }

        return $id;
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

    private function ProfileForState(string $stateName, int $type): string
    {
        if ($type === 0) {
            return '~Switch';
        }
        if ($type === 2) {
            $name = strtolower($stateName);
            if (str_contains($name, 'temperature')) {
                return '~Temperature';
            }
            if (str_contains($name, 'humidity')) {
                return '~Humidity';
            }
        }
        return '';
    }

    private function BuildNameMap(array $items): array
    {
        $map = [];
        foreach ($items as $uuid => $item) {
            if (is_array($item)) {
                $map[(string)$uuid] = (string)($item['name'] ?? $uuid);
            }
        }
        return $map;
    }

    private function GetOrCreateCategory(int $parentId, string $name, string $ident, int $position = 0): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($id === false) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $parentId);
            IPS_SetIdent($id, $ident);
        }

        IPS_SetName($id, $name);
        IPS_SetPosition($id, $position);
        return $id;
    }

    private function CreateOrUpdateStringVariable(int $parentId, string $name, string $ident, string $value, int $position): int
    {
        $id = $this->GetOrCreateVariable($parentId, $name, $ident, 3, $position);
        SetValueString($id, $value);
        return $id;
    }

    private function CreateOrUpdateIntegerVariable(int $parentId, string $name, string $ident, int $value, int $position): int
    {
        $id = $this->GetOrCreateVariable($parentId, $name, $ident, 1, $position);
        SetValueInteger($id, $value);
        return $id;
    }

    private function GetOrCreateVariable(int $parentId, string $name, string $ident, int $type, int $position): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($id === false) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $parentId);
            IPS_SetIdent($id, $ident);
        }

        IPS_SetName($id, $name);
        IPS_SetPosition($id, $position);
        return $id;
    }

    private function CountArray(array $data, string $key): int
    {
        return isset($data[$key]) && is_array($data[$key]) ? count($data[$key]) : 0;
    }

    private function IconForControlType(string $type): string
    {
        $type = strtolower($type);
        if (str_contains($type, 'light')) {
            return 'Bulb';
        }
        if (str_contains($type, 'jalousie') || str_contains($type, 'blind')) {
            return 'Shutter';
        }
        if (str_contains($type, 'audio')) {
            return 'Speaker';
        }
        if (str_contains($type, 'nfc') || str_contains($type, 'door')) {
            return 'Lock';
        }
        if (str_contains($type, 'alarm')) {
            return 'Warning';
        }
        if (str_contains($type, 'weather')) {
            return 'Cloud';
        }
        if (str_contains($type, 'switch')) {
            return 'Power';
        }
        return 'Database';
    }

    private function IdentFromUuid(string $uuid): string
    {
        return str_replace('-', '_', strtolower($uuid));
    }

    private function IdentFromUuidOrString(string $uuid, string $fallback): string
    {
        if ($uuid !== '') {
            return $this->IdentFromUuid($uuid);
        }
        return $this->IdentFromString($fallback);
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



    public function GetStateValue(string $stateUuid)
    {
        if (trim($stateUuid) === '') {
            throw new RuntimeException('State UUID ist leer.');
        }

        return $this->CreateApi()->getIoValue($stateUuid);
    }

    public function RefreshAllDeviceStates()
    {
        $deviceModuleGuid = '{7A18B2F4-2FA0-4D78-9E19-6D445A182C10}';
        $instanceIds = IPS_GetInstanceListByModuleID($deviceModuleGuid);
        $updated = 0;
        $errors = [];

        foreach ($instanceIds as $instanceId) {
            $gatewayId = (int)IPS_GetProperty($instanceId, 'GatewayID');
            if ($gatewayId !== $this->InstanceID) {
                continue;
            }

            try {
                $message = LOXD_RefreshStateValues($instanceId);
                if (preg_match('/Aktualisiert: (\d+)/', $message, $matches) === 1) {
                    $updated += (int)$matches[1];
                }
            } catch (Throwable $e) {
                $name = IPS_GetName($instanceId);
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        $text = "Statuswerte aktualisiert
" .
            "Geräte geprüft: " . count($instanceIds) . "
" .
            "Variablen aktualisiert: " . $updated;

        if (count($errors) > 0) {
            $text .= "

Fehler:
" . implode("
", array_slice($errors, 0, 10));
            if (count($errors) > 10) {
                $text .= "
... weitere " . (count($errors) - 10) . " Fehler";
            }
        }

        return $text;
    }


    public function BuildStateIndex()
    {
        try {
            $index = $this->CreateStateIndex();
            $this->SetBuffer('StateIndexJson', json_encode($index, JSON_UNESCAPED_UNICODE));
            $this->SetValue('StateIndexSize', count($index));
            $this->SetValue('LiveEngineStatus', 'State-Index bereit. WebSocket-Transport folgt im nächsten Sprint.');

            return "State-Index erstellt\n" .
                "Einträge: " . count($index) . "\n\n" .
                "Dieser Index ist die Grundlage für WebSocket-Livewerte:\n" .
                "Loxone State UUID → Symcon VariableID";
        } catch (Throwable $e) {
            return "Fehler beim Erstellen des State-Index:\n" . $e->getMessage();
        }
    }

    public function RefreshIndexedStateValues()
    {
        try {
            $index = $this->GetStateIndex();
            if (count($index) === 0) {
                $index = $this->CreateStateIndex();
                $this->SetBuffer('StateIndexJson', json_encode($index, JSON_UNESCAPED_UNICODE));
                $this->SetValue('StateIndexSize', count($index));
            }

            $api = $this->CreateApi();
            $updated = 0;
            $skipped = 0;
            $errors = [];

            foreach ($index as $stateUuid => $entry) {
                if (!$this->IsStateHttpReadable($entry)) {
                    $skipped++;
                    continue;
                }

                try {
                    $rawValue = $api->getIoValue((string)$stateUuid);
                    $this->ApplyLiveStateValue((string)$stateUuid, $rawValue);
                    $updated++;
                } catch (Throwable $e) {
                    $errors[] = ($entry['controlName'] ?? $stateUuid) . ' / ' . ($entry['stateName'] ?? '') . ': ' . $e->getMessage();
                }
            }

            $readable = $this->CountHttpReadableStates($index);
            $this->SetValue('LiveEngineStatus', 'HTTP-Snapshot abgeschlossen');
            $this->SetValue('LastLiveUpdate', date('Y-m-d H:i:s'));
            $this->SetValue('ReadableStateIndexSize', $readable);

            $text = "HTTP-Snapshot abgeschlossen
" .
                "Index-Einträge: " . count($index) . "
" .
                "HTTP-lesbar: " . $readable . "
" .
                "Aktualisiert: " . $updated . "
" .
                "Übersprungen: " . $skipped;

            if (count($errors) > 0) {
                $text .= "

Fehler: " . count($errors) . "
" . implode("
", array_slice($errors, 0, 10));
            }

            return $text;
        } catch (Throwable $e) {
            return "Fehler beim HTTP-Snapshot:
" . $e->getMessage();
        }
    }

    public function ApplyLiveStateValue(string $stateUuid, $rawValue): bool
    {
        $index = $this->GetStateIndex();
        if (!isset($index[$stateUuid])) {
            return false;
        }

        $entry = $index[$stateUuid];
        $variableId = (int)($entry['variableId'] ?? 0);
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return false;
        }

        $this->SetTypedVariableValue($variableId, $rawValue);
        $this->SetValue('LastLiveUpdate', date('Y-m-d H:i:s') . ' ' . (string)($entry['controlName'] ?? '') . ' / ' . (string)($entry['stateName'] ?? ''));
        return true;
    }

    private function GetStateIndex(): array
    {
        $json = $this->GetBuffer('StateIndexJson');
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function CreateStateIndex(): array
    {
        $deviceModuleGuid = '{7A18B2F4-2FA0-4D78-9E19-6D445A182C10}';
        $instanceIds = IPS_GetInstanceListByModuleID($deviceModuleGuid);
        $index = [];

        foreach ($instanceIds as $instanceId) {
            $gatewayId = (int)IPS_GetProperty($instanceId, 'GatewayID');
            if ($gatewayId !== $this->InstanceID) {
                continue;
            }

            $states = json_decode((string)IPS_GetProperty($instanceId, 'StatesJson'), true);
            if (!is_array($states)) {
                continue;
            }

            foreach ($states as $stateName => $stateUuid) {
                $ident = 'State_' . $this->IdentFromString((string)$stateName);
                $variableId = @IPS_GetObjectIDByIdent($ident, $instanceId);
                if ($variableId === false) {
                    continue;
                }

                $index[(string)$stateUuid] = [
                    'instanceId' => $instanceId,
                    'variableId' => $variableId,
                    'stateName' => (string)$stateName,
                    'stateUuid' => (string)$stateUuid,
                    'controlName' => (string)IPS_GetProperty($instanceId, 'ControlName'),
                    'controlType' => (string)IPS_GetProperty($instanceId, 'ControlType')
                ];
            }
        }

        ksort($index);
        return $index;
    }

    private function SetTypedVariableValue(int $variableId, $rawValue): void
    {
        $variable = IPS_GetVariable($variableId);
        $type = (int)$variable['VariableType'];

        switch ($type) {
            case 0:
                if (is_bool($rawValue)) {
                    SetValueBoolean($variableId, $rawValue);
                } elseif (is_numeric($rawValue)) {
                    SetValueBoolean($variableId, ((float)$rawValue) != 0.0);
                } else {
                    SetValueBoolean($variableId, in_array(strtolower((string)$rawValue), ['1', 'true', 'on', 'ein', 'yes'], true));
                }
                break;
            case 1:
                SetValueInteger($variableId, (int)$rawValue);
                break;
            case 2:
                SetValueFloat($variableId, (float)$rawValue);
                break;
            default:
                if (is_array($rawValue) || is_object($rawValue)) {
                    SetValueString($variableId, json_encode($rawValue, JSON_UNESCAPED_UNICODE));
                } else {
                    SetValueString($variableId, (string)$rawValue);
                }
                break;
        }
    }

    private function CountHttpReadableStates(array $index): int
    {
        $count = 0;
        foreach ($index as $entry) {
            if ($this->IsStateHttpReadable($entry)) {
                $count++;
            }
        }
        return $count;
    }

    private function IsStateHttpReadable(array $entry): bool
    {
        $controlType = (string)($entry['controlType'] ?? '');
        $stateName = (string)($entry['stateName'] ?? '');

        $unsupportedControlTypes = [
            'Daytimer',
            'NfcCodeTouch',
            'AudioZone',
            'AudioServer',
            'Intercom'
        ];
        if (in_array($controlType, $unsupportedControlTypes, true)) {
            return false;
        }

        $unsupportedStates = [
            'entriesAndDefaultValue',
            'resetActive',
            'modeList',
            'historyDate',
            'codeDate',
            'events',
            'lastid',
            'lastuser',
            'lasttag',
            'lastcode',
            'keyPadAuthType',
            'nfcLearnResult'
        ];
        if (in_array($stateName, $unsupportedStates, true)) {
            return false;
        }

        return true;
    }

    public function GetWebSocketInfo()
    {
        $scheme = $this->ReadPropertyBoolean('UseHttps') ? 'wss' : 'ws';
        $host = trim($this->ReadPropertyString('Host'));
        $port = $this->ReadPropertyInteger('Port');
        $url = sprintf('%s://%s:%d/ws/rfc6455', $scheme, $host, $port);

        $this->SetValue('LiveEngineStatus', 'WebSocket vorbereitet: ' . $url);

        return "WebSocket vorbereitet
" .
            "URL: " . $url . "

" .
            "Der State-Index ist die Zuordnung StateUUID → VariableID. Im nächsten Schritt wird der Transport daran angeschlossen.";
    }

    private function CreateApi(): SymconLoxoneAPI
    {
        return new SymconLoxoneAPI(
            trim($this->ReadPropertyString('Host')),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyBoolean('UseHttps'),
            $this->ReadPropertyString('Username'),
            $this->ReadPropertyString('Password')
        );
    }
}
