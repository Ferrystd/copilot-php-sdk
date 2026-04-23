<?php

declare(strict_types=1);

namespace Github\Copilot\V0;

use Github\Copilot\V0\Events\SessionEvent;
use Github\Copilot\V0\Exceptions\SessionException;
use Github\Copilot\V0\JsonRpc\Connection;
use Github\Copilot\V0\Types\MessageOptions;
use Github\Copilot\V0\Types\Tool;

/**
 * Represents a single conversation session with the Copilot CLI.
 *
 * A session maintains conversation state, handles streaming events, and
 * manages tool execution.  Sessions are created via
 * {@see CopilotClient::createSession()} and should be disconnected when
 * no longer needed.
 *
 * ## Example
 *
 * ```php
 * $session = $client->createSession(new SessionConfig(model: 'gpt-4.1'));
 *
 * $session->on(function (SessionEvent $event): void {
 *     if ($event->isAssistantMessage()) {
 *         echo $event->getAssistantContent();
 *     }
 * });
 *
 * $session->send(new MessageOptions(prompt: 'What is 2+2?'));
 * $session->pumpUntilIdle();   // block until session.idle
 *
 * $session->disconnect();
 * ```
 */
final class CopilotSession
{
    /** All-events subscribers. */
    private array $eventHandlers = [];

    /** Per-type subscribers, keyed by event type. */
    private array $typedEventHandlers = [];

    /** Tool handlers keyed by tool name. */
    private array $toolHandlers = [];

    /** A single permission handler (last one registered wins). */
    private mixed $permissionHandler = null;

    private bool $disconnected = false;

    /**
     * @param string     $sessionId   Unique session identifier.
     * @param Connection $connection  Shared JSON-RPC connection.
     * @param list<Tool> $tools       Tools registered for this session.
     */
    public function __construct(
        public readonly string $sessionId,
        private readonly Connection $connection,
        array $tools = [],
    ) {
        foreach ($tools as $tool) {
            if ($tool->handler !== null) {
                $this->toolHandlers[$tool->name] = $tool->handler;
            }
        }

        // Register the shared notification router for this session.
        $this->connection->onNotification(
            'session.event',
            function (array $params): void {
                $sid = $params['sessionId'] ?? null;
                if ($sid !== null && $sid !== $this->sessionId) {
                    return; // Not for this session.
                }
                $event = SessionEvent::fromNotificationParams($params);
                $this->dispatchEvent($event);
            }
        );
    }

    // =========================================================================
    // Messaging
    // =========================================================================

    /**
     * Send a message (prompt) to this session.
     *
     * This is non-blocking: events arrive asynchronously; call
     * {@see pumpUntilIdle()} or {@see sendAndWait()} to wait for completion.
     *
     * @return string The messageId assigned by the server.
     *
     * @throws \Github\Copilot\V0\Exceptions\RpcException  on server-side errors.
     * @throws \Github\Copilot\V0\Exceptions\ConnectionException  on transport errors.
     */
    public function send(MessageOptions|string $options): string
    {
        $this->assertConnected();

        if (is_string($options)) {
            $options = new MessageOptions(prompt: $options);
        }

        $params = [
            'sessionId' => $this->sessionId,
            'prompt'    => $options->prompt,
        ];

        if ($options->attachments !== []) {
            $params['attachments'] = $options->attachments;
        }

        if ($options->mode !== null) {
            $params['mode'] = $options->mode;
        }

        /** @var array<string,mixed> $response */
        $response = $this->connection->sendRequest('session.send', $params);

        return (string) ($response['messageId'] ?? '');
    }

    /**
     * Send a message and block until the session becomes idle.
     *
     * While waiting, all received events are dispatched to registered handlers.
     *
     * @param float $timeout Maximum seconds to wait (default 60).
     *
     * @return SessionEvent|null The last "assistant.message" event, or null if none arrived.
     *
     * @throws SessionException    if the session emits a "session.error" event.
     * @throws \Github\Copilot\V0\Exceptions\ConnectionException  on timeout or transport errors.
     */
    public function sendAndWait(
        MessageOptions|string $options,
        float $timeout = 60.0,
    ): ?SessionEvent {
        $lastAssistantMessage = null;
        $idle  = false;
        $error = null;

        // Register a temporary event handler BEFORE calling send to avoid
        // the race condition where session.idle fires before we start listening.
        $unsubscribe = $this->on(static function (SessionEvent $event) use (
            &$lastAssistantMessage,
            &$idle,
            &$error,
        ): void {
            if ($event->isAssistantMessage()) {
                $lastAssistantMessage = $event;
            } elseif ($event->isIdle()) {
                $idle = true;
            } elseif ($event->isError()) {
                $error = $event->getErrorMessage() ?? 'Unknown session error';
            }
        });

        try {
            $this->send($options);
            $this->pumpUntilCondition(
                static fn () => $idle || $error !== null,
                $timeout,
                'Timeout waiting for session.idle'
            );
        } finally {
            $unsubscribe();
        }

        if ($error !== null) {
            throw new SessionException($error);
        }

        return $lastAssistantMessage;
    }

    /**
     * Pump the message loop until the session becomes idle.
     *
     * @param float $timeout Maximum seconds to wait.
     *
     * @throws \Github\Copilot\V0\Exceptions\ConnectionException on timeout.
     */
    public function pumpUntilIdle(float $timeout = 60.0): void
    {
        $idle = false;
        $unsubscribe = $this->on(static function (SessionEvent $event) use (&$idle): void {
            if ($event->isIdle()) {
                $idle = true;
            }
        });

        try {
            $this->pumpUntilCondition(
                static fn () => $idle,
                $timeout,
                'Timeout waiting for session.idle'
            );
        } finally {
            $unsubscribe();
        }
    }

    // =========================================================================
    // Event subscription
    // =========================================================================

    /**
     * Subscribe to all events from this session.
     *
     * @param callable(SessionEvent): void $handler
     *
     * @return callable Unsubscribe callback — call it to remove the handler.
     */
    public function on(callable $handler): callable
    {
        $this->eventHandlers[] = $handler;

        return function () use ($handler): void {
            $this->eventHandlers = array_values(
                array_filter($this->eventHandlers, fn ($h) => $h !== $handler)
            );
        };
    }

    /**
     * Subscribe to a specific event type.
     *
     * @param string   $eventType  Event type string (e.g. "assistant.message").
     * @param callable(SessionEvent): void $handler
     *
     * @return callable Unsubscribe callback.
     */
    public function onType(string $eventType, callable $handler): callable
    {
        $this->typedEventHandlers[$eventType][] = $handler;

        return function () use ($eventType, $handler): void {
            $this->typedEventHandlers[$eventType] = array_values(
                array_filter(
                    $this->typedEventHandlers[$eventType] ?? [],
                    fn ($h) => $h !== $handler
                )
            );
        };
    }

    // =========================================================================
    // Permission handling
    // =========================================================================

    /**
     * Register a permission handler.
     *
     * The handler signature is:
     *   callable(array $request, array $context): array
     *
     * It must return one of:
     *   ['kind' => 'approved']
     *   ['kind' => 'denied-interactively-by-user', 'feedback' => '...']
     *   ['kind' => 'denied-by-rules', 'rules' => [...]]
     *   ... (any PermissionDecision shape)
     *
     * Use {@see approveAll()} for a convenience handler that approves everything.
     */
    public function registerPermissionHandler(callable $handler): void
    {
        $this->permissionHandler = $handler;
    }

    /**
     * Convenience: returns a permission handler that approves all requests.
     */
    public static function approveAll(): callable
    {
        return static fn () => ['kind' => 'approved'];
    }

    // =========================================================================
    // Session management
    // =========================================================================

    /**
     * Disconnect this session, releasing server-side resources.
     *
     * After calling disconnect, no further operations may be performed on
     * this session.
     */
    public function disconnect(): void
    {
        if ($this->disconnected) {
            return;
        }
        $this->disconnected = true;

        try {
            $this->connection->sendRequest('session.disconnect', [
                'sessionId' => $this->sessionId,
            ]);
        } catch (\Throwable) {
            // Best-effort: ignore errors during disconnect.
        }
    }

    /**
     * Get the current model for this session.
     *
     * @return array<string,mixed>
     */
    public function getCurrentModel(): array
    {
        $this->assertConnected();
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest(
            'session.model.getCurrent',
            ['sessionId' => $this->sessionId]
        );
        return $result;
    }

    /**
     * Switch the model used by this session.
     *
     * @return array<string,mixed>
     */
    public function switchModel(string $modelId, ?string $reasoningEffort = null): array
    {
        $this->assertConnected();
        $params = ['sessionId' => $this->sessionId, 'modelId' => $modelId];
        if ($reasoningEffort !== null) {
            $params['reasoningEffort'] = $reasoningEffort;
        }
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest('session.model.switchTo', $params);
        return $result;
    }

    /**
     * Get the conversation history for this session.
     *
     * @return list<array<string,mixed>>
     */
    public function getMessages(): array
    {
        $this->assertConnected();
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest(
            'session.messages.get',
            ['sessionId' => $this->sessionId]
        );
        return (array) ($result['messages'] ?? []);
    }

    /**
     * Pump the message loop once without blocking.
     *
     * Useful in tight loops where you want to process events without calling
     * sendAndWait().
     */
    public function pump(float $timeout = 0.1): void
    {
        $this->connection->pump($timeout);
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Pump until $condition() returns true or $timeout elapses.
     */
    private function pumpUntilCondition(
        callable $condition,
        float $timeout,
        string $timeoutMessage,
    ): void {
        $deadline = microtime(true) + $timeout;

        while (!$condition()) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new \Github\Copilot\V0\Exceptions\ConnectionException($timeoutMessage);
            }
            $this->connection->pump(min($remaining, 0.1));
        }
    }

    /**
     * Dispatch a session event to all registered handlers and handle
     * server-initiated requests (permissions, tool calls).
     */
    private function dispatchEvent(SessionEvent $event): void
    {
        // Handle server-initiated requests inline.
        $this->handleBroadcastEvent($event);

        // Typed handlers.
        foreach ($this->typedEventHandlers[$event->type] ?? [] as $handler) {
            try {
                $handler($event);
            } catch (\Throwable) {
                // Never let a handler kill the dispatch loop.
            }
        }

        // Wildcard handlers.
        foreach ($this->eventHandlers as $handler) {
            try {
                $handler($event);
            } catch (\Throwable) {
                // Never let a handler kill the dispatch loop.
            }
        }
    }

    /**
     * Handle events that require an RPC reply (permissions, tool calls).
     */
    private function handleBroadcastEvent(SessionEvent $event): void
    {
        switch ($event->type) {
            case 'permission.requested':
                $this->handlePermissionRequest($event);
                break;

            case 'external_tool.requested':
                $this->handleToolCall($event);
                break;
        }
    }

    private function handlePermissionRequest(SessionEvent $event): void
    {
        $requestId         = (string) ($event->data['requestId']         ?? '');
        $permissionRequest = (array)  ($event->data['permissionRequest'] ?? []);
        $resolvedByHook    = (bool)   ($event->data['resolvedByHook']    ?? false);

        if ($resolvedByHook || $requestId === '') {
            return;
        }

        $handler = $this->permissionHandler ?? static fn () => ['kind' => 'approved'];

        try {
            $result = $handler($permissionRequest, ['sessionId' => $this->sessionId]);
        } catch (\Throwable) {
            $result = ['kind' => 'denied-no-approval-rule-and-could-not-request-from-user'];
        }

        try {
            $this->connection->sendRequest(
                'session.permissions.handlePendingPermissionRequest',
                [
                    'sessionId' => $this->sessionId,
                    'requestId' => $requestId,
                    'result'    => $result,
                ]
            );
        } catch (\Throwable) {
            // Best-effort.
        }
    }

    private function handleToolCall(SessionEvent $event): void
    {
        $requestId  = (string) ($event->data['requestId']  ?? '');
        $toolName   = (string) ($event->data['toolName']   ?? '');
        $arguments  = $event->data['arguments'] ?? null;
        $toolCallId = (string) ($event->data['toolCallId'] ?? '');

        if ($requestId === '' || $toolName === '') {
            return;
        }

        $handler = $this->toolHandlers[$toolName] ?? null;
        if ($handler === null) {
            return;
        }

        try {
            $raw = $handler($arguments, [
                'sessionId'  => $this->sessionId,
                'toolCallId' => $toolCallId,
                'toolName'   => $toolName,
                'arguments'  => $arguments,
            ]);

            $result = match (true) {
                $raw === null              => '',
                is_string($raw)            => $raw,
                is_array($raw)             => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                default                    => (string) $raw,
            };

            $this->connection->sendRequest(
                'session.tools.handlePendingToolCall',
                [
                    'sessionId' => $this->sessionId,
                    'requestId' => $requestId,
                    'result'    => $result,
                ]
            );
        } catch (\Throwable $e) {
            try {
                $this->connection->sendRequest(
                    'session.tools.handlePendingToolCall',
                    [
                        'sessionId' => $this->sessionId,
                        'requestId' => $requestId,
                        'error'     => $e->getMessage(),
                    ]
                );
            } catch (\Throwable) {
                // Best-effort.
            }
        }
    }

    private function assertConnected(): void
    {
        if ($this->disconnected) {
            throw new SessionException(
                "Session {$this->sessionId} has been disconnected."
            );
        }
    }
}
