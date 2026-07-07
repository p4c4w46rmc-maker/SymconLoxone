<?php

declare(strict_types=1);

class SymconLoxoneWebSocket
{
    private string $host;
    private int $port;
    private bool $https;

    public function __construct(string $host, int $port, bool $https)
    {
        $this->host = $host;
        $this->port = $port;
        $this->https = $https;
    }

    public function getUrl(): string
    {
        $scheme = $this->https ? 'wss' : 'ws';
        return sprintf('%s://%s:%d/ws/rfc6455', $scheme, $this->host, $this->port);
    }
}
