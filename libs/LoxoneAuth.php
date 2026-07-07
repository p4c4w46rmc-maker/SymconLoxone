<?php

declare(strict_types=1);

/**
 * Authentication helper for Loxone Miniserver.
 *
 * Sprint 8 intentionally separates the authentication calculations from the
 * gateway. The class performs getkey2, derives the password hash and then tries
 * the legacy token endpoint. JWT/encrypted command support is planned after this
 * diagnostic step.
 */
class SymconLoxoneAuth
{
    private string $host;
    private int $port;
    private bool $https;
    private string $username;
    private string $password;

    public function __construct(string $host, int $port, bool $https, string $username, string $password)
    {
        $this->host = trim($host);
        $this->port = $port;
        $this->https = $https;
        $this->username = $username;
        $this->password = $password;
    }

    public function getKey2(): array
    {
        if ($this->username === '') {
            throw new RuntimeException('Benutzer ist leer.');
        }
        $cmd = 'jdev/sys/getkey2/' . rawurlencode($this->username);
        $response = $this->requestJson($cmd);
        $value = $response['LL']['value'] ?? null;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (!is_array($value)) {
            throw new RuntimeException('getkey2 lieferte keine verwertbare value-Struktur: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
        }
        return [
            'raw' => $response,
            'code' => (string)($response['LL']['Code'] ?? ''),
            'key' => (string)($value['key'] ?? ''),
            'salt' => (string)($value['salt'] ?? ''),
            'hashAlg' => strtoupper((string)($value['hashAlg'] ?? 'SHA1')),
            'value' => $value
        ];
    }

    public function testLegacyTokenAcquisition(): array
    {
        $keyInfo = $this->getKey2();
        $pwHash = $this->createPasswordHash($this->password, (string)$keyInfo['salt'], (string)$keyInfo['hashAlg']);
        $loginHash = $this->createLoginHash($this->username, $pwHash, (string)$keyInfo['key'], (string)$keyInfo['hashAlg']);

        $clientUuid = '098802e1-02b4-603c-ffffeee000d80cfd';
        $permission = 4; // App token; longer-lived than Web token.
        $info = rawurlencode('IP-Symcon SymconLoxone');
        $cmd = 'jdev/sys/gettoken/' . $loginHash . '/' . rawurlencode($this->username) . '/' . $permission . '/' . $clientUuid . '/' . $info;

        $tokenResponse = null;
        $tokenCode = null;
        $token = '';
        $note = '';
        try {
            $tokenResponse = $this->requestJson($cmd);
            $tokenCode = (string)($tokenResponse['LL']['Code'] ?? '');
            $value = $tokenResponse['LL']['value'] ?? null;
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }
            if (is_array($value)) {
                $token = (string)($value['token'] ?? $value['jwt'] ?? '');
            }
            if ($tokenCode !== '200') {
                $note = 'Falls der Miniserver gettoken unverschlüsselt ablehnt, ist der nächste Schritt die verschlüsselte JWT-Anforderung über WebSocket.';
            }
        } catch (Throwable $e) {
            $tokenCode = 'EXCEPTION';
            $note = $e->getMessage();
        }

        return [
            'status' => $token !== '' ? 'Token erhalten' : 'Token noch nicht erhalten',
            'getkey2Code' => $keyInfo['code'],
            'hashAlg' => $keyInfo['hashAlg'],
            'keyLength' => strlen((string)$keyInfo['key']),
            'saltLength' => strlen((string)$keyInfo['salt']),
            'passwordHashCreated' => $pwHash !== '',
            'loginHashCreated' => $loginHash !== '',
            'passwordHashPreview' => substr($pwHash, 0, 12) . '...',
            'loginHashPreview' => substr($loginHash, 0, 12) . '...',
            'tokenCode' => $tokenCode,
            'token' => $token,
            'tokenPreview' => $token !== '' ? substr($token, 0, 20) . '...' : '',
            'note' => $note,
            'rawTokenResponse' => is_array($tokenResponse) ? json_encode($tokenResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ''
        ];
    }

    public function hashTokenForAuthentication(string $token, array $keyInfo): string
    {
        return $this->hmacHex($token, (string)$keyInfo['key'], (string)$keyInfo['hashAlg']);
    }

    private function createPasswordHash(string $password, string $salt, string $hashAlg): string
    {
        $alg = $this->normalizeHashAlg($hashAlg);
        return strtoupper(hash($alg, $password . ':' . $salt));
    }

    private function createLoginHash(string $username, string $passwordHash, string $keyHex, string $hashAlg): string
    {
        return $this->hmacHex($username . ':' . $passwordHash, $keyHex, $hashAlg);
    }

    private function hmacHex(string $text, string $keyHex, string $hashAlg): string
    {
        $alg = $this->normalizeHashAlg($hashAlg);
        $key = @hex2bin($keyHex);
        if ($key === false) {
            // Some older endpoints may return a non-hex key. Keep a fallback for diagnostics.
            $key = $keyHex;
        }
        return hash_hmac($alg, $text, $key);
    }

    private function normalizeHashAlg(string $hashAlg): string
    {
        $alg = strtolower(str_replace('-', '', $hashAlg));
        if ($alg === 'sha256') {
            return 'sha256';
        }
        if ($alg === 'sha1') {
            return 'sha1';
        }
        throw new RuntimeException('Nicht unterstützter Hash-Algorithmus: ' . $hashAlg);
    }

    private function requestJson(string $command): array
    {
        $body = $this->request($command);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Antwort ist kein JSON: ' . substr($body, 0, 500));
        }
        return $json;
    }

    private function request(string $command): string
    {
        $scheme = $this->https ? 'https' : 'http';
        $path = ltrim($command, '/');
        $url = sprintf('%s://%s:%d/%s', $scheme, $this->host, $this->port, $path);

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
                'verify_peer_name' => false,
                'allow_self_signed' => true
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
