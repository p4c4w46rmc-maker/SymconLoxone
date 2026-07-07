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

        $this->RegisterVariableString('Version', 'Miniserver Version', '', 10);
        $this->RegisterVariableString('ProjectName', 'Projektname', '', 20);
        $this->RegisterVariableString('SerialNumber', 'Seriennummer', '', 30);
        $this->RegisterVariableString('CurrentUser', 'Benutzer', '', 40);
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
            $api = $this->CreateApi();
            $data = $api->getLoxApp3();

            $msInfo = $data['msInfo'] ?? [];
            $projectName = (string) ($msInfo['projectName'] ?? '');
            $serialNumber = (string) ($msInfo['serialNr'] ?? '');
            $currentUser = (string) ($msInfo['currentUser']['name'] ?? '');

            $this->SetValue('ProjectName', $projectName);
            $this->SetValue('SerialNumber', $serialNumber);
            $this->SetValue('CurrentUser', $currentUser);

            $rooms = isset($data['rooms']) && is_array($data['rooms']) ? count($data['rooms']) : 0;
            $cats = isset($data['cats']) && is_array($data['cats']) ? count($data['cats']) : 0;
            $controls = isset($data['controls']) && is_array($data['controls']) ? count($data['controls']) : 0;

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
        try {
            $api = $this->CreateApi();
            $data = $api->getLoxApp3();

            $rootId = $this->GetOrCreateCategory($this->InstanceID, 'Loxone', 'loxone_root');
            $roomsRootId = $this->GetOrCreateCategory($rootId, 'Räume', 'rooms');
            $catsRootId = $this->GetOrCreateCategory($rootId, 'Kategorien', 'categories');
            $controlsRootId = $this->GetOrCreateCategory($rootId, 'Controls', 'controls');

            $roomNames = [];
            foreach (($data['rooms'] ?? []) as $uuid => $room) {
                $name = (string)($room['name'] ?? $uuid);
                $roomNames[$uuid] = $name;
                $this->GetOrCreateCategory($roomsRootId, $name, 'room_' . $this->IdentFromUuid($uuid));
            }

            $catNames = [];
            foreach (($data['cats'] ?? []) as $uuid => $cat) {
                $name = (string)($cat['name'] ?? $uuid);
                $catNames[$uuid] = $name;
                $this->GetOrCreateCategory($catsRootId, $name, 'cat_' . $this->IdentFromUuid($uuid));
            }

            $importedControls = 0;
            $importedStates = 0;

            foreach (($data['controls'] ?? []) as $uuid => $control) {
                $controlName = (string)($control['name'] ?? $uuid);
                $controlType = (string)($control['type'] ?? 'Unknown');
                $roomUuid = (string)($control['room'] ?? '');
                $catUuid = (string)($control['cat'] ?? '');

                $roomName = $roomNames[$roomUuid] ?? 'Nicht zugeordnet';
                $catName = $catNames[$catUuid] ?? 'Nicht zugeordnet';

                $roomContainerId = $this->GetOrCreateCategory($controlsRootId, $roomName, 'controls_room_' . $this->IdentFromString($roomName));
                $controlId = $this->GetOrCreateCategory($roomContainerId, $controlName, 'control_' . $this->IdentFromUuid($uuid));

                $this->CreateOrUpdateStringVariable($controlId, 'Typ', 'type', $controlType, 10);
                $this->CreateOrUpdateStringVariable($controlId, 'UUID Action', 'uuid_action', (string)($control['uuidAction'] ?? $uuid), 20);
                $this->CreateOrUpdateStringVariable($controlId, 'Raum', 'room', $roomName, 30);
                $this->CreateOrUpdateStringVariable($controlId, 'Kategorie', 'category', $catName, 40);

                $stateRootId = $this->GetOrCreateCategory($controlId, 'States', 'states');
                foreach (($control['states'] ?? []) as $stateName => $stateUuid) {
                    $this->CreateOrUpdateStringVariable(
                        $stateRootId,
                        (string)$stateName,
                        'state_' . $this->IdentFromString((string)$stateName),
                        (string)$stateUuid,
                        10 + $importedStates
                    );
                    $importedStates++;
                }

                $importedControls++;
            }

            return "Loxone Objektbaum importiert\n" .
                "Räume: " . count($roomNames) . "\n" .
                "Kategorien: " . count($catNames) . "\n" .
                "Controls: " . $importedControls . "\n" .
                "States: " . $importedStates;
        } catch (Throwable $e) {
            return "Fehler beim Import:\n" . $e->getMessage();
        }
    }

    private function GetOrCreateCategory(int $parentId, string $name, string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($id === false) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $parentId);
            IPS_SetIdent($id, $ident);
        }

        IPS_SetName($id, $name);
        return $id;
    }

    private function CreateOrUpdateStringVariable(int $parentId, string $name, string $ident, string $value, int $position): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($id === false) {
            $id = IPS_CreateVariable(3);
            IPS_SetParent($id, $parentId);
            IPS_SetIdent($id, $ident);
        }

        IPS_SetName($id, $name);
        IPS_SetPosition($id, $position);
        SetValueString($id, $value);
        return $id;
    }

    private function IdentFromUuid(string $uuid): string
    {
        return str_replace('-', '_', strtolower($uuid));
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

    private function CreateApi()
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
