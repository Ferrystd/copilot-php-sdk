<?php

declare(strict_types=1);

namespace Github\Copilot\Tests\V1;

use Github\Copilot\V1\Events\SessionEvent;
use PHPUnit\Framework\TestCase;

final class SessionEventTest extends TestCase
{
    public function testAssistantMessageAndDeltaHelpers(): void
    {
        $message = new SessionEvent('assistant.message', ['content' => 'hello']);
        self::assertTrue($message->isAssistantMessage());
        self::assertFalse($message->isAssistantMessageDelta());
        self::assertSame('hello', $message->getAssistantContent());
        self::assertNull($message->getDelta());

        $delta = new SessionEvent('assistant.message_delta', ['delta' => 'he']);
        self::assertTrue($delta->isAssistantMessageDelta());
        self::assertSame('he', $delta->getDelta());
        self::assertNull($delta->getAssistantContent());
    }

    public function testErrorIdleAndRequestHelpers(): void
    {
        $error = new SessionEvent('session.error', ['message' => 'boom', 'requestId' => 'r1']);
        self::assertTrue($error->isError());
        self::assertSame('boom', $error->getErrorMessage());
        self::assertSame('r1', $error->getRequestId());

        $idle = new SessionEvent('session.idle', []);
        self::assertTrue($idle->isIdle());

        $toolCall = new SessionEvent('external_tool.requested', [
            'requestId' => 'r2',
            'toolName' => 'weather',
            'arguments' => ['city' => 'Shenzhen'],
        ]);
        self::assertTrue($toolCall->isToolCall());
        self::assertSame('r2', $toolCall->getRequestId());
        self::assertSame('weather', $toolCall->getToolName());
        self::assertSame(['city' => 'Shenzhen'], $toolCall->getToolArguments());
    }

    public function testShellOutputAndFromNotificationParams(): void
    {
        $event = SessionEvent::fromNotificationParams([
            'type' => 'shell.output',
            'eventId' => 'evt-1',
            'data' => ['output' => 'done', 'processId' => 'p-1'],
        ]);

        self::assertTrue($event->isShellOutput());
        self::assertSame('evt-1', $event->eventId);
        self::assertSame('done', $event->getShellOutput());
        self::assertSame('p-1', $event->getShellProcessId());
    }

    public function testCommandAndInputPredicates(): void
    {
        self::assertTrue((new SessionEvent('command.execute', []))->isCommandExecute());
        self::assertTrue((new SessionEvent('user_input.requested', []))->isUserInputRequest());
        self::assertTrue((new SessionEvent('elicitation.requested', []))->isElicitationRequest());
        self::assertTrue((new SessionEvent('permission.requested', []))->isPermissionRequest());
    }
}
