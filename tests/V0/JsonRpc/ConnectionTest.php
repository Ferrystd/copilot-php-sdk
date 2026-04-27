<?php

declare(strict_types=1);

namespace Github\Copilot\Tests\V0\JsonRpc;

use Github\Copilot\V0\Exceptions\ConnectionException;
use Github\Copilot\V0\Exceptions\RpcException;
use Github\Copilot\V0\JsonRpc\Connection;
use Github\Copilot\V0\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the JSON-RPC Connection class using a mock transport.
 */
final class ConnectionTest extends TestCase
{
    // =========================================================================
    // Helpers — in-memory transport stub
    // =========================================================================

    /**
     * Create an in-memory transport that replays a pre-configured sequence of
     * raw bytes for reads and captures all written bytes.
     *
     * @param string $readBuffer  Raw bytes the transport will return on read().
     */
    private function makeTransport(string $readBuffer = ''): object
    {
        return new class ($readBuffer) implements TransportInterface {
            public string $written = '';
            private int $readPos  = 0;

            public function __construct(private string $buffer)
            {
            }

            public function write(string $data): void
            {
                $this->written .= $data;
            }

            public function read(int $length): string
            {
                $chunk = substr($this->buffer, $this->readPos, $length);
                if (strlen($chunk) < $length) {
                    throw new ConnectionException('Transport buffer exhausted');
                }
                $this->readPos += strlen($chunk);
                return $chunk;
            }

            public function isReadable(float $timeout = 0.0): bool
            {
                return $this->readPos < strlen($this->buffer);
            }

            public function close(): void
            {
            }

            public function isOpen(): bool
            {
                return true;
            }
        };
    }

    /**
     * Encode a JSON-RPC message using LSP framing (Content-Length header).
     *
     * @param array<string,mixed> $payload
     */
    private function frame(array $payload): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return "Content-Length: " . strlen($body) . "\r\n\r\n" . $body;
    }

    // =========================================================================
    // sendRequest — happy path
    // =========================================================================

    public function testSendRequestReturnsResultOnSuccess(): void
    {
        $responsePayload = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['message' => 'pong', 'timestamp' => 1000, 'protocolVersion' => 3]];
        $transport = $this->makeTransport($this->frame($responsePayload));

        $conn   = new Connection($transport);
        $result = $conn->sendRequest('ping', ['message' => 'ping']);

        self::assertIsArray($result);
        self::assertSame('pong', $result['message']);
        self::assertSame(3, $result['protocolVersion']);
    }

    public function testSendRequestWritesValidJsonRpcEnvelope(): void
    {
        $responsePayload = ['jsonrpc' => '2.0', 'id' => 1, 'result' => []];
        $transport = $this->makeTransport($this->frame($responsePayload));

        $conn = new Connection($transport);
        $conn->sendRequest('models.list', []);

        // Decode the written bytes
        $written = $transport->written;
        $this->assertStringContainsString('Content-Length:', $written);

        // Extract and parse the JSON body
        $body = substr($written, strpos($written, "\r\n\r\n") + 4);
        $decoded = json_decode($body, true);

        self::assertSame('2.0',          $decoded['jsonrpc']);
        self::assertSame('models.list',  $decoded['method']);
        self::assertSame(1,              $decoded['id']);
        self::assertIsArray($decoded['params']);
    }

    // =========================================================================
    // sendRequest — error response
    // =========================================================================

    public function testSendRequestThrowsRpcExceptionOnErrorResponse(): void
    {
        $errorResponse = [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'error'   => ['code' => -32601, 'message' => 'Method not found'],
        ];
        $transport = $this->makeTransport($this->frame($errorResponse));
        $conn      = new Connection($transport);

        $this->expectException(RpcException::class);
        $this->expectExceptionMessage('Method not found');

        $conn->sendRequest('nonexistent.method', []);
    }

    public function testRpcExceptionCarriesCodeAndData(): void
    {
        $errorResponse = [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'error'   => [
                'code'    => -32600,
                'message' => 'Invalid request',
                'data'    => ['detail' => 'missing field'],
            ],
        ];
        $transport = $this->makeTransport($this->frame($errorResponse));
        $conn      = new Connection($transport);

        try {
            $conn->sendRequest('test', []);
            self::fail('Expected RpcException');
        } catch (RpcException $e) {
            self::assertSame(-32600, $e->getRpcCode());
            self::assertIsArray($e->getData());
            self::assertSame('missing field', $e->getData()['detail']);
        }
    }

    // =========================================================================
    // Notifications
    // =========================================================================

    public function testNotificationsAreDispatchedDuringPump(): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method'  => 'session.event',
            'params'  => ['sessionId' => 'abc', 'type' => 'assistant.message', 'data' => ['content' => 'hello']],
        ];
        $transport = $this->makeTransport($this->frame($notification));
        $conn      = new Connection($transport);

        $received = [];
        $conn->onNotification('session.event', function (array $params) use (&$received): void {
            $received[] = $params;
        });

        $conn->pump(0.0);

        self::assertCount(1, $received);
        self::assertSame('assistant.message', $received[0]['type']);
    }

    public function testMultipleNotificationHandlersForSameMethod(): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method'  => 'session.event',
            'params'  => ['type' => 'session.idle', 'data' => []],
        ];
        $transport = $this->makeTransport($this->frame($notification));
        $conn      = new Connection($transport);

        $calls = [];
        $conn->onNotification('session.event', function () use (&$calls): void { $calls[] = 'h1'; });
        $conn->onNotification('session.event', function () use (&$calls): void { $calls[] = 'h2'; });

        $conn->pump(0.0);

        self::assertSame(['h1', 'h2'], $calls);
    }

    public function testRemoveNotificationHandlers(): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method'  => 'session.event',
            'params'  => ['type' => 'session.idle', 'data' => []],
        ];
        $transport = $this->makeTransport($this->frame($notification));
        $conn      = new Connection($transport);

        $called = false;
        $conn->onNotification('session.event', function () use (&$called): void { $called = true; });
        $conn->removeNotificationHandlers('session.event');

        $conn->pump(0.0);

        self::assertFalse($called);
    }

    // =========================================================================
    // sendNotification
    // =========================================================================

    public function testSendNotificationWritesMessageWithoutId(): void
    {
        $transport = $this->makeTransport('');
        $conn      = new Connection($transport);

        $conn->sendNotification('some.notify', ['key' => 'value']);

        $body    = substr($transport->written, strpos($transport->written, "\r\n\r\n") + 4);
        $decoded = json_decode($body, true);

        self::assertArrayNotHasKey('id', $decoded);
        self::assertSame('some.notify', $decoded['method']);
        self::assertSame(['key' => 'value'], $decoded['params']);
    }

    // =========================================================================
    // isOpen / close
    // =========================================================================

    public function testIsOpenReturnsFalseAfterClose(): void
    {
        $transport = $this->makeTransport('');
        $conn      = new Connection($transport);

        self::assertTrue($conn->isOpen());
        $conn->close();
        self::assertFalse($conn->isOpen());
    }

    // =========================================================================
    // Sequential requests — IDs increment correctly
    // =========================================================================

    public function testRequestIdsIncrementAcrossMultipleCalls(): void
    {
        $r1 = ['jsonrpc' => '2.0', 'id' => 1, 'result' => 'first'];
        $r2 = ['jsonrpc' => '2.0', 'id' => 2, 'result' => 'second'];

        $transport = $this->makeTransport($this->frame($r1) . $this->frame($r2));
        $conn      = new Connection($transport);

        $conn->sendRequest('method.one', []);
        $conn->sendRequest('method.two', []);

        $written = $transport->written;

        // Extract all JSON bodies from the written data.
        $bodies = [];
        $offset = 0;
        while (($pos = strpos($written, 'Content-Length:', $offset)) !== false) {
            $headerEnd = strpos($written, "\r\n\r\n", $pos);
            $header    = substr($written, $pos, $headerEnd - $pos);
            preg_match('/Content-Length:\s*(\d+)/', $header, $m);
            $len     = (int) $m[1];
            $bodyStart = $headerEnd + 4;
            $body    = substr($written, $bodyStart, $len);
            $bodies[] = json_decode($body, true);
            $offset  = $bodyStart + $len;
        }

        self::assertCount(2, $bodies);
        self::assertSame(1, $bodies[0]['id']);
        self::assertSame(2, $bodies[1]['id']);
    }
}
