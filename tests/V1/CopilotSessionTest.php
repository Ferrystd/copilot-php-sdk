<?php

declare(strict_types=1);

namespace Github\Copilot\Tests\V1;

use Github\Copilot\V0\JsonRpc\Connection;
use Github\Copilot\V0\Transport\TransportInterface;
use Github\Copilot\V1\CopilotSession;
use Github\Copilot\V1\Events\SessionEvent;
use Github\Copilot\V1\Types\Command;
use Github\Copilot\V1\Types\MessageOptions;
use Github\Copilot\V1\Types\Tool;
use PHPUnit\Framework\TestCase;

final class CopilotSessionTest extends TestCase
{
    /** @return array{CopilotSession, Connection, object} */
    private function makeSession(string $readBuffer = '', array $tools = [], array $commands = []): array
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
                    throw new \Github\Copilot\V0\Exceptions\ConnectionException('Buffer exhausted');
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

        $conn = new Connection($transport);
        $session = new CopilotSession('test-session-id', $conn, $tools, $commands);

        return [$session, $conn, $transport];
    }

    /** @param array<string,mixed> $payload */
    private function frame(array $payload): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
    }

    public function testSendAndWaitReturnsAssistantMessage(): void
    {
        $sendResponse = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['messageId' => 'm-1']];
        $assistant = [
            'jsonrpc' => '2.0',
            'method' => 'session.event',
            'params' => [
                'sessionId' => 'test-session-id',
                'type' => SessionEvent::TYPE_ASSISTANT_MESSAGE,
                'data' => ['content' => '4'],
            ],
        ];
        $idle = [
            'jsonrpc' => '2.0',
            'method' => 'session.event',
            'params' => [
                'sessionId' => 'test-session-id',
                'type' => SessionEvent::TYPE_SESSION_IDLE,
                'data' => [],
            ],
        ];

        [$session] = $this->makeSession(
            $this->frame($sendResponse) . $this->frame($assistant) . $this->frame($idle)
        );

        $event = $session->sendAndWait(new MessageOptions('2+2?'), timeout: 1.0);

        self::assertNotNull($event);
        self::assertSame('4', $event->getAssistantContent());
    }

    public function testPermissionRequestIsHandled(): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method' => 'session.event',
            'params' => [
                'sessionId' => 'test-session-id',
                'type' => SessionEvent::TYPE_PERMISSION_REQUESTED,
                'data' => [
                    'requestId' => 'perm-1',
                    'permissionRequest' => ['kind' => 'write'],
                ],
            ],
        ];
        $response = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]];

        [$session, $conn, $transport] = $this->makeSession($this->frame($notification) . $this->frame($response));
        $session->registerPermissionHandler(fn () => ['kind' => 'approved']);

        $conn->pump(0.0);

        self::assertStringContainsString('session.permissions.handlePendingPermissionRequest', $transport->written);
        self::assertStringContainsString('perm-1', $transport->written);
    }

    public function testToolCallIsHandled(): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method' => 'session.event',
            'params' => [
                'sessionId' => 'test-session-id',
                'type' => SessionEvent::TYPE_EXTERNAL_TOOL_REQUESTED,
                'data' => [
                    'requestId' => 'tool-1',
                    'toolName' => 'echo_tool',
                    'arguments' => ['value' => 'ok'],
                ],
            ],
        ];
        $response = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]];

        $tool = new Tool('echo_tool', 'echo', handler: fn (array $args) => $args['value']);
        [$session, $conn, $transport] = $this->makeSession(
            $this->frame($notification) . $this->frame($response),
            [$tool],
        );

        $conn->pump(0.0);

        self::assertStringContainsString('session.tools.handlePendingToolCall', $transport->written);
        self::assertStringContainsString('"result":"ok"', $transport->written);
    }

    public function testCommandExecuteIsHandled(): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method' => 'session.event',
            'params' => [
                'sessionId' => 'test-session-id',
                'type' => SessionEvent::TYPE_COMMAND_EXECUTE,
                'data' => [
                    'requestId' => 'cmd-1',
                    'commandName' => 'deploy',
                    'command' => '/deploy',
                    'args' => 'prod',
                ],
            ],
        ];
        $response = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]];

        $called = false;
        $command = new Command('deploy', 'Deploy', handler: function () use (&$called): void {
            $called = true;
        });

        [, $conn, $transport] = $this->makeSession(
            $this->frame($notification) . $this->frame($response),
            [],
            [$command],
        );

        $conn->pump(0.0);

        self::assertTrue($called);
        self::assertStringContainsString('session.commands.handlePendingCommand', $transport->written);
        self::assertStringContainsString('"success":true', $transport->written);
    }

    public function testUserInputAndElicitationAreHandled(): void
    {
        $userInputNotification = [
            'jsonrpc' => '2.0',
            'method' => 'session.event',
            'params' => [
                'sessionId' => 'test-session-id',
                'type' => SessionEvent::TYPE_USER_INPUT_REQUESTED,
                'data' => ['requestId' => 'ui-1', 'prompt' => 'name?'],
            ],
        ];
        $elicitationNotification = [
            'jsonrpc' => '2.0',
            'method' => 'session.event',
            'params' => [
                'sessionId' => 'test-session-id',
                'type' => SessionEvent::TYPE_ELICITATION_REQUESTED,
                'data' => ['requestId' => 'el-1', 'message' => 'confirm'],
            ],
        ];

        $response1 = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]];
        $response2 = ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['ok' => true]];

        [$session, $conn, $transport] = $this->makeSession(
            $this->frame($userInputNotification)
            . $this->frame($response1)
            . $this->frame($elicitationNotification)
            . $this->frame($response2)
        );

        $session->registerUserInputHandler(fn () => 'alice');
        $session->registerElicitationHandler(fn () => ['action' => 'accept', 'content' => ['confirm' => true]]);

        $conn->pump(0.0);
        $conn->pump(0.0);

        self::assertStringContainsString('session.ui.handlePendingUserInput', $transport->written);
        self::assertStringContainsString('"input":"alice"', $transport->written);
        self::assertStringContainsString('session.ui.handlePendingElicitation', $transport->written);
        self::assertStringContainsString('"action":"accept"', $transport->written);
    }
}
