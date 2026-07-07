<?php

declare(strict_types=1);

class SymconLoxoneAPI
{
    private string $host;
    private int $port;
    private bool $https;
    private string $username;
    private string $password;

    public function __construct(string $host, int $port, bool $https, string $username, string $password)
    {
        $this->host = $host;
        $this->port = $port;
        $this->https = $https;
        $this->username = $username;
        $this->password = $password;
    }

    public function getVersion(): array
    {
        return $this->getJson('/jdev/cfg/version');
    }

    public function getLoxApp3(): array
    {
        return $this->getJson('/data/LoxAPP3.json');
    }

    private function getJson(string $path): array
    {
        $body = $this->request($path);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new RuntimeException('Antwort ist kein gültiges JSON: ' . substr($body, 0, 500));
        }

        return $data;
    }

    private function request(string $path): string
    {
        if ($this->host === '') {
            throw new RuntimeException('Host ist leer.');
        }

        $scheme = $this->https ? 'https' : 'http';
        $url = sprintf('%s://%s:%d%s', $scheme, $this->host, $this->port, $path);

        $headers = [];
        if ($this->username !== '' || $this->password !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 10,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $error = error_get_last();
            throw new RuntimeException('HTTP-Aufruf fehlgeschlagen: ' . ($error['message'] ?? $url));
        }

        return $body;
    }
}
