<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Transport;

use Github\Copilot\V0\Exceptions\ConnectionException;

/**
 * TCP transport — connects to an already-running Copilot CLI server via a TCP socket.
 *
 * Supports URL formats accepted by the reference SDKs:
 *   - "host:port"
 *   - "http://host:port"
 *   - "https://host:port"
 *   - "<port>"  (localhost implied)
 */
final class TcpTransport implements TransportInterface
{
    /** @var resource|null */
    private $socket = null;

    private bool $closed = false;

    /**
     * @param string $host    Hostname or IP address.
     * @param int    $port    TCP port.
     * @param float  $connectTimeout  Maximum seconds to wait for the connection.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $connectTimeout = 10.0,
    ) {
    }

    /**
     * Establish the TCP connection (called lazily by the first write/read, or
     * explicitly by {@see \Github\Copilot\V0\CopilotClient}).
     *
     * @throws ConnectionException if the connection cannot be established.
     */
    public function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }

        $errorCode    = 0;
        $errorMessage = '';

        $socket = @fsockopen(
            $this->host,
            $this->port,
            $errorCode,
            $errorMessage,
            $this->connectTimeout
        );

        if ($socket === false) {
            throw new ConnectionException(
                "Cannot connect to {$this->host}:{$this->port} — {$errorMessage} (code {$errorCode})"
            );
        }

        $this->socket = $socket;
        stream_set_blocking($this->socket, false);
    }

    // -------------------------------------------------------------------------
    // TransportInterface implementation
    // -------------------------------------------------------------------------

    public function write(string $data): void
    {
        $this->ensureConnected();

        $total   = strlen($data);
        $written = 0;

        while ($written < $total) {
            $chunk = fwrite($this->socket, substr($data, $written));
            if ($chunk === false) {
                throw new ConnectionException('Failed to write to TCP socket');
            }
            $written += $chunk;
        }
    }

    public function read(int $length): string
    {
        $this->ensureConnected();

        $buffer = '';

        while (strlen($buffer) < $length) {
            $remaining = $length - strlen($buffer);

            if (!$this->isReadable(30.0)) {
                throw new ConnectionException('Timed out waiting for data from TCP socket');
            }

            $chunk = fread($this->socket, $remaining);

            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                $this->closed = true;
                throw new ConnectionException('TCP connection closed unexpectedly');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    public function isReadable(float $timeout = 0.0): bool
    {
        if (!is_resource($this->socket)) {
            return false;
        }

        $read    = [$this->socket];
        $write   = null;
        $except  = null;
        $sec     = (int) $timeout;
        $usec    = (int)(($timeout - $sec) * 1_000_000);

        $result = stream_select($read, $write, $except, $sec, $usec);

        return $result !== false && $result > 0;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function isOpen(): bool
    {
        return !$this->closed && is_resource($this->socket);
    }

    // -------------------------------------------------------------------------
    // Static factory
    // -------------------------------------------------------------------------

    /**
     * Parse a URL string in any of the supported formats and return a TcpTransport.
     *
     * @throws \InvalidArgumentException for malformed URLs.
     */
    public static function fromUrl(string $url, float $connectTimeout = 10.0): self
    {
        // Strip optional protocol prefix.
        $clean = preg_replace('#^https?://#', '', $url) ?? $url;

        // Just a port number?
        if (ctype_digit($clean)) {
            return new self('localhost', (int) $clean, $connectTimeout);
        }

        $parts = explode(':', $clean);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException(
                "Invalid cliUrl format: \"{$url}\". Expected \"host:port\", \"http://host:port\", or just \"port\"."
            );
        }

        $host = $parts[0] ?: 'localhost';
        $port = (int) $parts[1];

        if ($port <= 0 || $port > 65535) {
            throw new \InvalidArgumentException("Invalid port in cliUrl: \"{$url}\".");
        }

        return new self($host, $port, $connectTimeout);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function ensureConnected(): void
    {
        if ($this->socket === null) {
            $this->connect();
        }

        if (!$this->isOpen()) {
            throw new ConnectionException('Transport is closed');
        }
    }
}
