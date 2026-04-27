<?php

declare(strict_types=1);

namespace Github\Copilot\Tests\V0;

use Github\Copilot\V0\CopilotSession;
use Github\Copilot\V0\Events\SessionEvent;
use Github\Copilot\V0\Exceptions\SessionException;
use Github\Copilot\V0\JsonRpc\Connection;
use Github\Copilot\V0\Transport\TransportInterface;
use Github\Copilot\V0\Types\MessageOptions;
use Github\Copilot\V0\Types\Tool;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CopilotSession class.
 *
 * We use a fake Connection backed by an in-memory transport so no real CLI
 * process is needed.
 */
final class CopilotSessionTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a fully self-contained (session, connection, transport) triple.
     *
     * The transport feeds an initial sequence of raw LSP-framed messages.
     * Any writes by the session (sendRequest, sendNotification) are captured
     * in $transport->written.
     *
     * @param string $readBuffer  Pre-canned raw bytes to replay.
     * @param list<Tool> $tools   Tools to register on the session.
     *
     * @return array{CopilotSession, Connection, object}
     */
    private function makeSession(string $readBuffer = '', array $tools = []): array
    {
        $transport = new class ($readBuffer) implements TransportInterface {
            public string $written = '';
            private int $readPos = 0;

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
                    throw new \Github\Copilot\V0\Exceptions\ConnectionException(
                        'Buffer exhausted'
                    );
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

        $conn    = new Connection($transport);
        $session = new CopilotSession('test-session-id', $conn, $tools);

        return [$session, $conn, $transport];
    }

    /**
     * LSP-frame a JSON payload.
     *
     * @param array<string,mixed> $payload
     */
    private function frame(array $payload): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return "Content-Length: " . strlen($body) . "\r\n\r\n" . $body;
    }

    // =========================================================================
    // send()
    // =========================================================================

    public function testSendWritesSessionSendRequest(): void
    {
        $sendResponse = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['messageId' => 'msg-001']];
        [$session, , $transport] = $this->makeSession($this->frame($sendResponse));

        $messageId = $session->send(new MessageOptions('Hello'));

        self::assertSame('msg-001', $messageId);

        $body    = substr($transport->written, strpos($transport->written, "\r\n\r\n") + 4);
        $decoded = json_decode($body, true);

        self::assertSame('session.send', $decoded['method']);
        self::assertSame('test-session-id', $decoded['params']['sessionId']);
        self::assertSame('Hello', $decoded['params']['prompt']);
    }

    public function testSendAcceptsStringShorthand(): void
    {
        $sendResponse = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['messageId' => 'msg-002']];
        [$session, , ] = $this->makeSession($this->frame($sendResponse));

        $messageId = $session->send('Direct string prompt');
        self::assertSame('msg-002', $messageId);
    }

    // =========================================================================
    // on() / onType()
    // =========================================================================

    public function testOnReceivesDispatchedEvents(): void
    {
        [$session] = $this->makeSession();

        $received = [];
        $session->on(function (SessionEvent $e) use (&$received): void {
            $received[] = $e->type;
        });

        // Manually dispatch an event via the notification handler we set up
        // in the constructor by pumping a fabricated notification.
        $notification = [
            'jsonrpc' => '2.0',
            'method'  => 'session.event',
            'params'  => [
                'sessionId' => 'test-session-id',
                'type'      => 'assistant.message',
                'data'      => ['content' => 'hi there'],
            ],
        ];

        [$session, $conn, $transport] = $this->makeSession($this->frame($notification));

        $captured = [];
        $session->on(function (SessionEvent $e) use (&$captured): void {
            $captured[] = $e->type;
        });

        $conn->pump(0.0); // process the notification

        self::assertSame(['assistant.message'], $captured);
    }

    public function testOnTypeFiltersEventsByType(): void
    {
        $idleNotification = [
            'jsonrpc' => '2.0',
            'method'  => 'session.event',
            'params'  => [
                'sessionId' => 'test-session-id',
                'type'      => 'session.idle',
                'data'      => [],
            ],
        ];

        [$session, $conn, ] = $this->makeSession($this->frame($idleNotification));

        $allCaptured  = [];
        $typedCaptured = [];

        $session->on(function (SessionEvent $e) use (&$allCaptured): void {
            $allCaptured[] = $e->type;
        });

        $session->onType('assistant.message', function (SessionEvent $e) use (&$typedCaptured): void {
            $typedCaptured[] = $e->type;
        });

        $conn->pump(0.0);

        // Wildcard handler fires; typed handler for 'assistant.message' does NOT.
        self::assertSame(['session.idle'], $allCaptured);
        self::assertSame([], $typedCaptured);
    }

    public function testOnReturnsUnsubscribeCallback(): void
    {
        $n1 = ['jsonrpc' => '2.0', 'method' => 'session.event', 'params' => ['sessionId' => 'test-session-id', 'type' => 'session.idle', 'data' => []]];
        $n2 = ['jsonrpc' => '2.0', 'method' => 'session.event', 'params' => ['sessionId' => 'test-session-id', 'type' => 'session.idle', 'data' => []]];

        [$session, $conn, ] = $this->makeSession($this->frame($n1) . $this->frame($n2));

        $count = 0;
        $unsub = $session->on(function () use (&$count): void { $count++; });

        $conn->pump(0.0); // fires once
        self::assertSame(1, $count);

        $unsub();

        $conn->pump(0.0); // should NOT fire after unsubscribe
        self::assertSame(1, $count);
    }

    // =========================================================================
    // SessionEvent helpers
    // =========================================================================

    public function testSessionEventIsAssistantMessage(): void
    {
        $event = new SessionEvent('assistant.message', ['content' => 'hello']);
        self::assertTrue($event->isAssistantMessage());
        self::assertFalse($event->isIdle());
        self::assertSame('hello', $event->getAssistantContent());
    }

    public function testSessionEventIsIdle(): void
    {
        $event = new SessionEvent('session.idle', []);
        self::assertTrue($event->isIdle());
        self::assertFalse($event->isAssistantMessage());
    }

    public function testSessionEventIsError(): void
    {
        $event = new SessionEvent('session.error', ['message' => 'oops']);
        self::assertTrue($event->isError());
        self::assertSame('oops', $event->getErrorMessage());
    }

    public function testSessionEventFromNotificationParams(): void
    {
        $params = [
            'type'    => 'assistant.message',
            'data'    => ['content' => 'world'],
            'eventId' => 'evt-42',
        ];
        $event = SessionEvent::fromNotificationParams($params);

        self::assertSame('assistant.message', $event->type);
        self::assertSame('world', $event->data['content']);
        self::assertSame('evt-42', $event->eventId);
    }

    // =========================================================================
    // disconnect()
    // =========================================================================

    public function testDisconnectSendsDisconnectRequest(): void
    {
        $disconnectResponse = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['success' => true]];
        [$session, , $transport] = $this->makeSession($this->frame($disconnectResponse));

        $session->disconnect();

        $body    = substr($transport->written, strpos($transport->written, "\r\n\r\n") + 4);
        $decoded = json_decode($body, true);

        self::assertSame('session.disconnect', $decoded['method']);
        self::assertSame('test-session-id', $decoded['params']['sessionId']);
    }

    public function testDisconnectIsIdempotent(): void
    {
        // Two disconnect responses: the second should not be sent.
        $r1 = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['success' => true]];
        [$session, , $transport] = $this->makeSession($this->frame($r1));

        $session->disconnect();
        $session->disconnect(); // second call is a no-op

        // Only one request should have been written.
        $writtenCount = substr_count($transport->written, '"session.disconnect"');
        self::assertSame(1, $writtenCount);
    }

    public function testSendAfterDisconnectThrowsSessionException(): void
    {
        $r1 = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['success' => true]];
        [$session, , ] = $this->makeSession($this->frame($r1));

        $session->disconnect();

        $this->expectException(SessionException::class);
        $session->send('should fail');
    }

    // =========================================================================
    // approveAll()
    // =========================================================================

    public function testApproveAllReturnsApprovedKind(): void
    {
        $handler = CopilotSession::approveAll();
        $result  = $handler(['some' => 'request'], ['sessionId' => 's1']);
        self::assertSame(['kind' => 'approved'], $result);
    }

    // =========================================================================
    // Tool execution via notification
    // =========================================================================

    public function testToolHandlerIsInvokedOnExternalToolRequested(): void
    {
        $toolCallNotification = [
            'jsonrpc' => '2.0',
            'method'  => 'session.event',
            'params'  => [
                'sessionId'  => 'test-session-id',
                'type'       => 'external_tool.requested',
                'data'       => [
                    'requestId'  => 'req-1',
                    'toolName'   => 'my_tool',
                    'toolCallId' => 'tc-1',
                    'arguments'  => ['x' => 42],
                ],
            ],
        ];
        $toolResponse = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['success' => true]];

        $tool = new Tool(
            name:        'my_tool',
            description: 'A test tool',
            handler:     fn ($args) => 'result:' . $args['x'],
        );

        [$session, $conn, $transport] = $this->makeSession(
            $this->frame($toolCallNotification) . $this->frame($toolResponse),
            [$tool]
        );

        $conn->pump(0.0); // process tool call notification

        // The connection should have sent session.tools.handlePendingToolCall
        self::assertStringContainsString('session.tools.handlePendingToolCall', $transport->written);
        self::assertStringContainsString('result:42', $transport->written);
    }
}
