<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Transport;

use Github\Copilot\V0\Exceptions\ConnectionException;

/**
 * Interface for transport implementations (stdio and TCP).
 *
 * All transports communicate using the LSP message framing format:
 *   Content-Length: <n>\r\n\r\n<json body>
 */
interface TransportInterface
{
    /**
     * Write data to the transport.
     *
     * @throws ConnectionException if the write fails
     */
    public function write(string $data): void;

    /**
     * Read exactly $length bytes from the transport.
     * Blocks until the data is available.
     *
     * @throws ConnectionException if the read fails or the connection is closed
     */
    public function read(int $length): string;

    /**
     * Check whether there is data available to read without blocking.
     * Uses a $timeout (in seconds, may be fractional) to wait.
     */
    public function isReadable(float $timeout = 0.0): bool;

    /**
     * Close the transport.
     */
    public function close(): void;

    /**
     * Returns true if the transport is still open and healthy.
     */
    public function isOpen(): bool;
}
