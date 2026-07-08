<?php

declare(strict_types=1);

class SymconLoxoneAPI
{
    private string $host;
    private int $port;
    private bool $https;
    private string $username;
    private string $password;

    private string $lastUrl = '';
    private string $lastHttpStatus = '';
    private string $lastBodyPreview = '';

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

    public function getIo(string $uuidOrName): array
    {
        return $this->getJson('/jdev/sps/io/' . rawurlencode($uuidOrName));
    }

    public function sendIoCommand(string $uuidOrName, string $command): array
    {
        $uuidOrName = trim($uuidOrName);
        $command = trim($command);
        if ($uuidOrName === '') {
            throw new RuntimeException('UUID ist leer.');
        }
        if ($command === '') {
            throw new RuntimeException('Befehl ist leer.');
        }

        return $this->getJson('/jdev/sps/io/' . rawurlencode($uuidOrName) . '/' . rawurlencode($command));
    }

    public function sendIoCommandChecked(string $uuidOrName, string $command)
    {
        $response = $this->sendIoCommand($uuidOrName, $command);
        if (!isset($response['LL'])) {
            throw new RuntimeException(
                'Loxone Antwort enthält keinen LL-Block. HTTP=' . $this->lastHttpStatus .
                ' URL=' . $this->lastUrl .
                ' Body=' . $this->lastBodyPreview
            );
        }

        $code = (string)($response['LL']['Code'] ?? '');
        if ($code !== '200') {
            throw new RuntimeException(
                'Loxone IO Befehl Antwortcode ist ' . $code .
                ' | URL=' . $this->lastUrl .
                ' | Antwort=' . json_encode($response, JSON_UNESCAPED_UNICODE)
            );
        }

        return $response['LL']['value'] ?? null;
    }

    public function getIoValue(string $uuidOrName)
    {
        $response = $this->getIo($uuidOrName);
        if (!isset($response['LL'])) {
            throw new RuntimeException(
                'Loxone Antwort enthält keinen LL-Block. HTTP=' . $this->lastHttpStatus .
                ' URL=' . $this->lastUrl .
                ' Body=' . $this->lastBodyPreview
            );
        }

        $code = (string)($response['LL']['Code'] ?? '');
        if ($code !== '200') {
            throw new RuntimeException(
                'Loxone IO Antwortcode ist ' . $code .
                ' | URL=' . $this->lastUrl .
                ' | Antwort=' . json_encode($response, JSON_UNESCAPED_UNICODE)
            );
        }

        return $response['LL']['value'] ?? null;
    }

    public function getLastDiagnostics(): array
    {
        return [
            'url' => $this->lastUrl,
            'httpStatus' => $this->lastHttpStatus,
            'bodyPreview' => $this->lastBodyPreview
        ];
    }

    private function getJson(string $path): array
    {
        $body = $this->request($path);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new RuntimeException(
                'Antwort ist kein gültiges JSON. HTTP=' . $this->lastHttpStatus .
                ' URL=' . $this->lastUrl .
                ' Body=' . substr($body, 0, 500)
            );
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
        $this->lastUrl = $url;
        $this->lastHttpStatus = '';
        $this->lastBodyPreview = '';

        $headers = [
            'Connection: close',
            'User-Agent: SymconLoxone/0.16.1',
            'Accept: application/json, text/plain, */*'
        ];
        if ($this->username !== '' || $this->password !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 3,
                'ignore_errors' => true,
                'protocol_version' => 1.0
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $body = @file_get_contents($url, false, $context);
        $statusLine = '';
        if (isset($http_response_header) && is_array($http_response_header) && count($http_response_header) > 0) {
            $statusLine = (string)$http_response_header[0];
        }
        $this->lastHttpStatus = $statusLine;

        if ($body === false) {
            $error = error_get_last();
            throw new RuntimeException(
                'HTTP-Aufruf fehlgeschlagen: ' . ($error['message'] ?? 'unbekannter Fehler') .
                ' | URL=' . $url .
                ' | HTTP=' . $statusLine
            );
        }

        $this->lastBodyPreview = substr($body, 0, 500);
        return $body;
    }
}
