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
