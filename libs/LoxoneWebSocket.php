<?php

declare(strict_types=1);

class SymconLoxoneWebSocket
{
    private string $host;
    private int $port;
    private bool $https;
    private string $username;
    private string $password;

    public function __construct(string $host, int $port, bool $https, string $username = '', string $password = '')
    {
        $this->host = trim($host);
        $this->port = $port;
        $this->https = $https;
        $this->username = $username;
        $this->password = $password;
    }

    public function getUrl(): string
    {
        $scheme = $this->https ? 'wss' : 'ws';
        return sprintf('%s://%s:%d/ws/rfc6455', $scheme, $this->host, $this->port);
    }

    public function testHandshake(int $timeoutSeconds = 5): array
    {
        if ($this->host === '') {
            throw new RuntimeException('Host ist leer.');
        }

        $transport = $this->https ? 'ssl' : 'tcp';
        $target = sprintf('%s://%s:%d', $transport, $this->host, $this->port);
        $errno = 0;
        $errstr = '';

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $socket = @stream_socket_client($target, $errno, $errstr, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new RuntimeException(sprintf('Socket-Verbindung fehlgeschlagen (%d): %s', $errno, $errstr));
        }

        stream_set_timeout($socket, $timeoutSeconds);

        $key = base64_encode(random_bytes(16));
        $headers = [
            'GET /ws/rfc6455 HTTP/1.1',
            'Host: ' . $this->host . ':' . $this->port,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13'
        ];

        if ($this->username !== '' || $this->password !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        $request = implode("\r\n", $headers) . "\r\n\r\n";
        fwrite($socket, $request);

        $response = '';
        while (!feof($socket)) {
            $chunk = fgets($socket, 4096);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
            if (str_contains($response, "\r\n\r\n")) {
                break;
            }
        }

        fclose($socket);

        $lines = preg_split('/\r?\n/', trim($response));
        $statusLine = is_array($lines) && isset($lines[0]) ? $lines[0] : '';
        $statusCode = 0;
        if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/', $statusLine, $m) === 1) {
            $statusCode = (int)$m[1];
        }

        return [
            'url' => $this->getUrl(),
            'statusLine' => $statusLine,
            'statusCode' => $statusCode,
            'isUpgrade' => $statusCode === 101,
            'rawHeaders' => $response
        ];
    }
}
