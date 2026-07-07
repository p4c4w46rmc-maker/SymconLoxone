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



    /**
     * Authenticates the WebSocket session, enables binary status updates and reads
     * incoming frames for a short diagnostic window. This is intentionally a bounded
     * probe. A later sprint will move the loop into a non-blocking/background-safe
     * transport for productive use inside IP-Symcon.
     */
    public function authenticatedLiveProbe(string $authCommand, int $readSeconds = 8): array
    {
        $socket = $this->openSocket(max(5, $readSeconds));
        $handshake = $this->performHandshake($socket, max(5, $readSeconds));
        if ((int)($handshake['statusCode'] ?? 0) !== 101) {
            fclose($socket);
            throw new RuntimeException('WebSocket Handshake fehlgeschlagen: ' . (string)($handshake['statusLine'] ?? ''));
        }

        $frames = [];

        // 1) Authenticate with an existing token hash.
        $this->writeFrame($socket, $authCommand);
        for ($i = 0; $i < 5; $i++) {
            $frame = $this->readFrame($socket, 2);
            if ($frame === null) {
                break;
            }
            $frame['stage'] = 'auth';
            $frame['binaryInfo'] = $this->decodeBinaryInfo((string)$frame['payload']);
            $frames[] = $frame;

            if ((int)$frame['opcode'] === 8) {
                fclose($socket);
                return $this->summarizeProbe($handshake, $frames, 'closed during auth');
            }

            if ((int)$frame['opcode'] === 1 && str_contains((string)$frame['payload'], 'validUntil')) {
                break;
            }
        }

        // 2) Ask the Miniserver to stream binary status updates.
        $enableCommand = 'jdev/sps/enablebinstatusupdate';
        $this->writeFrame($socket, $enableCommand);

        $start = time();
        while ((time() - $start) < $readSeconds) {
            $frame = $this->readFrame($socket, 1);
            if ($frame === null) {
                continue;
            }
            $frame['stage'] = 'live';
            $frame['binaryInfo'] = $this->decodeBinaryInfo((string)$frame['payload']);
            $frames[] = $frame;

            if ((int)$frame['opcode'] === 8) {
                break;
            }
        }

        fclose($socket);
        return $this->summarizeProbe($handshake, $frames, 'ok');
    }



    /**
     * Authenticates, enables binary status updates, decodes value-state packets
     * and returns decoded updates for a bounded diagnostic window.
     *
     * This is intentionally still a probe, but it uses the real Loxone binary
     * value-state format: 16 byte UUID + 8 byte little-endian double.
     */
    public function authenticatedDecodeProbe(string $authCommand, array $stateIndex, int $readSeconds = 10): array
    {
        $socket = $this->openSocket(max(5, $readSeconds));
        $handshake = $this->performHandshake($socket, max(5, $readSeconds));
        if ((int)($handshake['statusCode'] ?? 0) !== 101) {
            fclose($socket);
            throw new RuntimeException('WebSocket Handshake fehlgeschlagen: ' . (string)($handshake['statusLine'] ?? ''));
        }

        $frames = [];
        $decodedUpdates = [];
        $knownUpdates = 0;
        $unknownUpdates = 0;
        $packetTypes = [];
        $pendingHeader = null;

        // Authenticate with token.
        $this->writeFrame($socket, $authCommand);
        for ($i = 0; $i < 5; $i++) {
            $frame = $this->readFrame($socket, 2);
            if ($frame === null) {
                break;
            }
            $frame['stage'] = 'auth';
            $frame['binaryInfo'] = $this->decodeBinaryInfo((string)$frame['payload']);
            $frames[] = $frame;

            if ((int)$frame['opcode'] === 8) {
                fclose($socket);
                return $this->summarizeDecodeProbe($handshake, $frames, $decodedUpdates, $knownUpdates, $unknownUpdates, $packetTypes, 'closed during auth');
            }

            if ((int)$frame['opcode'] === 1 && str_contains((string)$frame['payload'], 'validUntil')) {
                break;
            }
        }

        // Enable binary live state updates.
        $this->writeFrame($socket, 'jdev/sps/enablebinstatusupdate');

        $start = time();
        while ((time() - $start) < $readSeconds) {
            $frame = $this->readFrame($socket, 1);
            if ($frame === null) {
                continue;
            }

            $frame['stage'] = 'live';
            $frame['binaryInfo'] = $this->decodeBinaryInfo((string)$frame['payload']);
            $frames[] = $frame;

            if ((int)$frame['opcode'] === 8) {
                break;
            }

            if ((int)$frame['opcode'] !== 2) {
                continue;
            }

            $payload = (string)$frame['payload'];
            $length = strlen($payload);

            // Loxone often sends the 8 byte binary package header as its own
            // WebSocket frame, followed by one frame containing the body.
            if ($length === 8) {
                $pendingHeader = $this->parseLoxoneBinaryHeader($payload);
                $packetTypes[] = $pendingHeader['type'];
                continue;
            }

            if ($pendingHeader !== null) {
                $type = (int)$pendingHeader['type'];
                $body = $payload;
                $pendingHeader = null;

                // 0x0203 / decimal 515: value-state event packet.
                if ($type === 515) {
                    foreach ($this->decodeValueStatePacket($body) as $update) {
                        $uuid = (string)$update['uuid'];
                        $value = (float)$update['value'];
                        $known = isset($stateIndex[$uuid]);
                        if ($known) {
                            $knownUpdates++;
                        } else {
                            $unknownUpdates++;
                        }
                        $update['known'] = $known;
                        $update['stateName'] = $known ? (string)($stateIndex[$uuid]['stateName'] ?? '') : '';
                        $update['controlName'] = $known ? (string)($stateIndex[$uuid]['controlName'] ?? '') : '';
                        $update['variableId'] = $known ? (int)($stateIndex[$uuid]['variableId'] ?? 0) : 0;
                        $decodedUpdates[] = $update;
                    }
                }
            }
        }

        fclose($socket);
        return $this->summarizeDecodeProbe($handshake, $frames, $decodedUpdates, $knownUpdates, $unknownUpdates, $packetTypes, 'ok');
    }

    private function summarizeDecodeProbe(array $handshake, array $frames, array $decodedUpdates, int $knownUpdates, int $unknownUpdates, array $packetTypes, string $status): array
    {
        $text = 0;
        $binary = 0;
        $close = 0;
        foreach ($frames as $frame) {
            $opcode = (int)($frame['opcode'] ?? -1);
            if ($opcode === 1) {
                $text++;
            } elseif ($opcode === 2) {
                $binary++;
            } elseif ($opcode === 8) {
                $close++;
            }
        }

        return [
            'url' => $this->getUrl(),
            'status' => $status,
            'handshake' => $handshake,
            'frameCount' => count($frames),
            'textFrames' => $text,
            'binaryFrames' => $binary,
            'closeFrames' => $close,
            'packetTypes' => array_values(array_unique($packetTypes)),
            'decodedUpdates' => $decodedUpdates,
            'decodedCount' => count($decodedUpdates),
            'knownUpdates' => $knownUpdates,
            'unknownUpdates' => $unknownUpdates,
            'frames' => $frames
        ];
    }

    private function parseLoxoneBinaryHeader(string $payload): array
    {
        $header = unpack('Vtype/Vsize', $payload);
        return [
            'type' => (int)($header['type'] ?? 0),
            'size' => (int)($header['size'] ?? 0)
        ];
    }

    private function decodeValueStatePacket(string $body): array
    {
        $updates = [];
        $entrySize = 24;
        $count = intdiv(strlen($body), $entrySize);
        for ($i = 0; $i < $count; $i++) {
            $offset = $i * $entrySize;
            $uuidBytes = substr($body, $offset, 16);
            $valueBytes = substr($body, $offset + 16, 8);
            if (strlen($uuidBytes) !== 16 || strlen($valueBytes) !== 8) {
                continue;
            }
            $updates[] = [
                'uuid' => $this->decodeLoxoneUuid($uuidBytes),
                'value' => $this->decodeLittleEndianDouble($valueBytes)
            ];
        }
        return $updates;
    }

    private function decodeLoxoneUuid(string $bytes): string
    {
        // Loxone stores UUIDs in LoxAPP3 as 8-4-4-16 hex groups,
        // for example: 1c010c72-0114-994b-05ff68530e15480b
        //
        // The first three groups are little-endian in the binary event
        // stream. The last 8 bytes are kept as one continuous 16 hex
        // character tail. A previous decoder used RFC4122-style
        // 8-4-4-4-12 formatting, which produced visually plausible UUIDs
        // but did not match the LoxAPP3 state IDs.
        return strtolower(
            bin2hex(strrev(substr($bytes, 0, 4))) . '-' .
            bin2hex(strrev(substr($bytes, 4, 2))) . '-' .
            bin2hex(strrev(substr($bytes, 6, 2))) . '-' .
            bin2hex(substr($bytes, 8, 8))
        );
    }

    private function decodeLittleEndianDouble(string $bytes): float
    {
        $value = @unpack('evalue', $bytes);
        if (is_array($value) && isset($value['value'])) {
            return (float)$value['value'];
        }
        // Fallback for environments without the explicit little-endian format.
        $value = unpack('dvalue', $bytes);
        return (float)($value['value'] ?? 0.0);
    }

    private function summarizeProbe(array $handshake, array $frames, string $status): array
    {
        $text = 0;
        $binary = 0;
        $close = 0;
        $other = 0;
        $live = 0;
        $auth = 0;

        foreach ($frames as $frame) {
            $opcode = (int)($frame['opcode'] ?? -1);
            if (($frame['stage'] ?? '') === 'live') {
                $live++;
            } elseif (($frame['stage'] ?? '') === 'auth') {
                $auth++;
            }

            if ($opcode === 1) {
                $text++;
            } elseif ($opcode === 2) {
                $binary++;
            } elseif ($opcode === 8) {
                $close++;
            } else {
                $other++;
            }
        }

        return [
            'url' => $this->getUrl(),
            'status' => $status,
            'handshake' => $handshake,
            'frameCount' => count($frames),
            'authFrames' => $auth,
            'liveFrames' => $live,
            'textFrames' => $text,
            'binaryFrames' => $binary,
            'closeFrames' => $close,
            'otherFrames' => $other,
            'frames' => $frames
        ];
    }

    private function decodeBinaryInfo(string $payload): array
    {
        $len = strlen($payload);
        if ($len < 8) {
            return [
                'hasHeader' => false,
                'length' => $len
            ];
        }

        $header = unpack('Vtype/Vsize', substr($payload, 0, 8));
        $type = (int)($header['type'] ?? -1);
        $size = (int)($header['size'] ?? 0);

        return [
            'hasHeader' => true,
            'length' => $len,
            'type' => $type,
            'size' => $size,
            'bodyLength' => max(0, $len - 8),
            'bodyHexPreview' => strtoupper(trim(chunk_split(bin2hex(substr($payload, 8, 64)), 2, ' ')))
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
