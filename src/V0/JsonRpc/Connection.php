<?php

declare(strict_types=1);

namespace Github\Copilot\V0\JsonRpc;

use Github\Copilot\V0\Exceptions\ConnectionException;
use Github\Copilot\V0\Exceptions\RpcException;
use Github\Copilot\V0\Transport\TransportInterface;

/**
 * JSON-RPC 2.0 connection over an arbitrary transport.
 *
 * Uses the same LSP message framing as vscode-jsonrpc:
 *
 *   Content-Length: <n>\r\n
 *   \r\n
 *   <json body of length n>
 *
 * Supports three roles:
 *  - **Request**: client sends a message with an id; server responds with the
 *    same id.
 *  - **Notification**: either side sends a message without an id; no response
 *    is expected.
 *  - **Response**: the reply to a request.
 *
 * ## Threading / concurrency model
 *
 * PHP is single-threaded.  The connection therefore works in a cooperative
 * manner: callers must periodically pump the read loop by calling
 * {@see pump()} so that incoming notifications and responses are dispatched.
 * {@see sendRequest()} calls pump() internally until the matching response
 * arrives.
 */
final class Connection
{
    /** JSON-RPC protocol version constant. */
    private const JSONRPC_VERSION = '2.0';

    /** Pending requests keyed by id, each holding a &$result reference slot. */
    private array $pendingRequests = [];

    /** Handlers for server-initiated notifications, keyed by method name. */
    private array $notificationHandlers = [];

    /** Monotonically-increasing request counter. */
    private int $nextId = 1;

    private bool $closed = false;

    public function __construct(private readonly TransportInterface $transport)
    {
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Send a JSON-RPC request and block until the response arrives.
     *
     * While waiting for the response, all received messages are dispatched
     * (notifications go to registered handlers, other responses are queued for
     * their respective callers).
     *
     * @param string  $method  RPC method name.
     * @param array<string,mixed> $params  Parameters to send.
     * @param float   $timeout Maximum seconds to wait for a response.
     *
     * @return mixed The "result" field of the JSON-RPC response.
     *
     * @throws RpcException        if the server returns an error response.
     * @throws ConnectionException if the transport closes or a timeout occurs.
     */
    public function sendRequest(string $method, array $params = [], float $timeout = 60.0): mixed
    {
        $id = $this->nextId++;

        $envelope = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id'      => $id,
            'method'  => $method,
            'params'  => $params,
        ];

        $this->writeMessage($envelope);

        // Pump until we receive the matching response.
        $deadline = microtime(true) + $timeout;

        $result    = null;
        $error     = null;
        $responded = false;

        $this->pendingRequests[$id] = [
            'result'    => &$result,
            'error'     => &$error,
            'responded' => &$responded,
        ];

        try {
            while (!$responded) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new ConnectionException(
                        "Timeout after {$timeout}s waiting for response to \"{$method}\" (id={$id})"
                    );
                }

                $this->pumpOnce(min($remaining, 1.0));
            }
        } finally {
            unset($this->pendingRequests[$id]);
        }

        if ($error !== null) {
            throw new RpcException(
                $error['message'] ?? 'Unknown RPC error',
                (int) ($error['code']   ?? 0),
                $error['data']  ?? null,
            );
        }

        return $result;
    }

    /**
     * Send a JSON-RPC notification (fire-and-forget, no response expected).
     *
     * @param string  $method  RPC method name.
     * @param array<string,mixed> $params  Parameters to send.
     *
     * @throws ConnectionException if writing fails.
     */
    public function sendNotification(string $method, array $params = []): void
    {
        $envelope = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method'  => $method,
            'params'  => $params,
        ];

        $this->writeMessage($envelope);
    }

    /**
     * Register a handler for server-initiated notifications with the given method name.
     *
     * Multiple handlers may be registered for the same method; they are called in
     * registration order.
     *
     * @param callable(array<string,mixed>): void $handler
     */
    public function onNotification(string $method, callable $handler): void
    {
        $this->notificationHandlers[$method][] = $handler;
    }

    /**
     * Remove all registered notification handlers for a given method.
     */
    public function removeNotificationHandlers(string $method): void
    {
        unset($this->notificationHandlers[$method]);
    }

    /**
     * Pump the message loop once, waiting up to $timeout seconds for data.
     *
     * Call this in a loop to process incoming notifications without blocking
     * on a full request/response cycle.
     *
     * @param float $timeout Seconds to wait (0 = non-blocking poll).
     */
    public function pump(float $timeout = 0.1): void
    {
        $this->pumpOnce($timeout);
    }

    /**
     * Close the connection and underlying transport.
     */
    public function close(): void
    {
        $this->closed = true;
        $this->transport->close();
    }

    /**
     * Returns true if the connection is still open.
     */
    public function isOpen(): bool
    {
        return !$this->closed && $this->transport->isOpen();
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Read and dispatch a single incoming message, waiting up to $timeout seconds.
     */
    private function pumpOnce(float $timeout): void
    {
        if (!$this->transport->isReadable($timeout)) {
            return;
        }

        $message = $this->readMessage();
        if ($message === null) {
            return;
        }

        $this->dispatch($message);
    }

    /**
     * Write a JSON-encoded message with LSP Content-Length framing.
     *
     * @param array<string,mixed> $message
     */
    private function writeMessage(array $message): void
    {
        $body = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Failed to JSON-encode RPC message: ' . json_last_error_msg());
        }

        $header = 'Content-Length: ' . strlen($body) . "\r\n\r\n";
        $this->transport->write($header . $body);
    }

    /**
     * Read a single LSP-framed message from the transport.
     *
     * Returns null if no data was available (caller already checked isReadable).
     *
     * @return array<string,mixed>|null
     */
    private function readMessage(): ?array
    {
        // --- Read headers -------------------------------------------------------
        $headerBlock = '';
        $lastTwo     = '';

        while (true) {
            $byte = $this->transport->read(1);
            $headerBlock .= $byte;
            $lastTwo      = substr($headerBlock, -4);

            if ($lastTwo === "\r\n\r\n") {
                break;
            }

            // Safety guard: headers should never be huge.
            if (strlen($headerBlock) > 4096) {
                throw new ConnectionException('Header block exceeds 4 KiB — corrupted stream?');
            }
        }

        // --- Parse Content-Length -----------------------------------------------
        $length = null;
        foreach (explode("\r\n", $headerBlock) as $line) {
            if (stripos($line, 'Content-Length:') === 0) {
                $length = (int) trim(substr($line, strlen('Content-Length:')));
                break;
            }
        }

        if ($length === null || $length <= 0) {
            throw new ConnectionException('Missing or invalid Content-Length header');
        }

        // --- Read body ----------------------------------------------------------
        $body = $this->transport->read($length);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ConnectionException('Failed to JSON-decode RPC message body');
        }

        return $decoded;
    }

    /**
     * Dispatch a decoded message to the appropriate handler.
     *
     * @param array<string,mixed> $message
     */
    private function dispatch(array $message): void
    {
        // Response to a pending request
        if (isset($message['id']) && !isset($message['method'])) {
            $id = $message['id'];
            if (isset($this->pendingRequests[$id])) {
                if (isset($message['error'])) {
                    $this->pendingRequests[$id]['error']     = $message['error'];
                } else {
                    $this->pendingRequests[$id]['result']    = $message['result'] ?? null;
                }
                $this->pendingRequests[$id]['responded'] = true;
            }
            return;
        }

        // Notification or server-initiated request (no 'id', or has 'id' + 'method')
        $method = $message['method'] ?? null;
        if ($method === null) {
            return;
        }

        $params = $message['params'] ?? [];

        $handlers = $this->notificationHandlers[$method] ?? [];
        foreach ($handlers as $handler) {
            $handler($params);
        }
    }
}
