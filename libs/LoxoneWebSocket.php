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
        $socket = $this->openSocket($timeoutSeconds);
        $result = $this->performHandshake($socket, $timeoutSeconds);
        fclose($socket);
        return $result;
    }

    /**
     * Opens a WebSocket, sends one Loxone command as a WebSocket text frame and reads a few frames.
     * This is a diagnostic step before we attach the permanent listener.
     */
    public function sendCommandAndRead(string $command, int $timeoutSeconds = 5, int $maxFrames = 5): array
    {
        $socket = $this->openSocket($timeoutSeconds);
        $handshake = $this->performHandshake($socket, $timeoutSeconds);
        if ((int)($handshake['statusCode'] ?? 0) !== 101) {
            fclose($socket);
            throw new RuntimeException('WebSocket Handshake fehlgeschlagen: ' . (string)($handshake['statusLine'] ?? ''));
        }

        $this->writeFrame($socket, $command);

        $frames = [];
        for ($i = 0; $i < $maxFrames; $i++) {
            $frame = $this->readFrame($socket, $timeoutSeconds);
            if ($frame === null) {
                break;
            }
            $frames[] = $frame;

            // Close frame
            if ((int)$frame['opcode'] === 8) {
                break;
            }
        }

        fclose($socket);

        return [
            'url' => $this->getUrl(),
            'command' => $command,
            'handshake' => $handshake,
            'frames' => $frames,
            'frameCount' => count($frames)
        ];
    }

    private function openSocket(int $timeoutSeconds)
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
        return $socket;
    }

    private function performHandshake($socket, int $timeoutSeconds): array
    {
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

    private function writeFrame($socket, string $payload): void
    {
        $length = strlen($payload);
        $header = chr(0x81); // FIN + text frame

        if ($length <= 125) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            // 64 bit length, high 32 bits are zero for practical command sizes
            $header .= chr(0x80 | 127) . pack('N2', 0, $length);
        }

        // Clients MUST mask frames.
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($socket, $header . $mask . $masked);
    }

    private function readFrame($socket, int $timeoutSeconds): ?array
    {
        stream_set_timeout($socket, $timeoutSeconds);
        $h = $this->readExact($socket, 2);
        if ($h === '') {
            return null;
        }

        $b1 = ord($h[0]);
        $b2 = ord($h[1]);
        $fin = ($b1 & 0x80) !== 0;
        $opcode = $b1 & 0x0f;
        $masked = ($b2 & 0x80) !== 0;
        $len = $b2 & 0x7f;

        if ($len === 126) {
            $ext = $this->readExact($socket, 2);
            if (strlen($ext) !== 2) {
                return null;
            }
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = $this->readExact($socket, 8);
            if (strlen($ext) !== 8) {
                return null;
            }
            $parts = unpack('Nhigh/Nlow', $ext);
            if ((int)$parts['high'] !== 0) {
                throw new RuntimeException('WebSocket Frame ist zu groß.');
            }
            $len = (int)$parts['low'];
        }

        $mask = '';
        if ($masked) {
            $mask = $this->readExact($socket, 4);
            if (strlen($mask) !== 4) {
                return null;
            }
        }

        $payload = $len > 0 ? $this->readExact($socket, $len) : '';
        if (strlen($payload) !== $len) {
            return null;
        }

        if ($masked) {
            $decoded = '';
            for ($i = 0; $i < $len; $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $decoded;
        }

        return [
            'fin' => $fin,
            'opcode' => $opcode,
            'length' => $len,
            'isText' => $opcode === 1,
            'isBinary' => $opcode === 2,
            'isClose' => $opcode === 8,
            'payload' => $payload,
            'preview' => $this->payloadPreview($payload, $opcode)
        ];
    }

    private function readExact($socket, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($socket)) {
            $chunk = fread($socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    break;
                }
                usleep(10000);
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function payloadPreview(string $payload, int $opcode): string
    {
        if ($opcode === 1) {
            return mb_substr($payload, 0, 1000);
        }

        $hex = strtoupper(bin2hex(substr($payload, 0, 200)));
        return trim(chunk_split($hex, 2, ' '));
    }
}
