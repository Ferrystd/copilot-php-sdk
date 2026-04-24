<?php

declare(strict_types=1);

namespace Github\Copilot\V1;

use Github\Copilot\V0\JsonRpc\Connection;
use Github\Copilot\V1\Events\SessionEvent;
use Github\Copilot\V1\Exceptions\ConnectionException;
use Github\Copilot\V1\Exceptions\SessionException;
use Github\Copilot\V1\Types\Command;
use Github\Copilot\V1\Types\MessageOptions;
use Github\Copilot\V1\Types\Tool;

/**
 * A single Copilot conversation session (V1).
 *
 * V1 exposes the complete session RPC surface defined in the reference SDK:
 *  - Messaging  (send, sendAndWait)
 *  - Model      (getCurrent, switchTo)
 *  - Mode       (get, set)
 *  - Name       (get, set)
 *  - Plan       (read, update, delete)
 *  - Workspaces (getWorkspace, listFiles, readFile, createFile)
 *  - Instructions (getSources)
 *  - History    (compact, truncate)
 *  - Usage      (getMetrics)
 *  - Shell      (exec, kill)
 *  - Log
 *  - Agent      (list, getCurrent, select, deselect, reload)
 *  - Skills     (list, enable, disable, reload)
 *  - MCP        (list, enable, disable, reload)
 *  - Extensions (list, enable, disable, reload)
 *  - Plugins    (list)
 *  - Commands   (handlePendingCommand)
 *  - Tools      (handlePendingToolCall)
 *  - UI         (elicitation, handlePendingElicitation)
 *  - Permissions (handlePendingPermissionRequest)
 *
 * @see \Github\Copilot\V1\CopilotClient::createSession()
 */
final class CopilotSession
{
    /** @var list<callable> */
    private array $eventHandlers = [];

    /** @var array<string, list<callable>> */
    private array $typedEventHandlers = [];

    /** @var array<string, callable> */
    private array $toolHandlers = [];

    /** @var array<string, callable> */
    private array $commandHandlers = [];

    private mixed $permissionHandler    = null;
    private mixed $userInputHandler     = null;
    private mixed $elicitationHandler   = null;

    private bool $disconnected = false;

    /**
     * @param string       $sessionId  Unique session identifier.
     * @param Connection   $connection Shared JSON-RPC connection.
     * @param list<Tool>   $tools      Custom tools.
     * @param list<Command> $commands  Custom slash-commands.
     */
    public function __construct(
        public readonly string $sessionId,
        private readonly Connection $connection,
        array $tools    = [],
        array $commands = [],
    ) {
        foreach ($tools as $tool) {
            if ($tool->handler !== null) {
                $this->toolHandlers[$tool->name] = $tool->handler;
            }
        }
        foreach ($commands as $command) {
            if ($command->handler !== null) {
                $this->commandHandlers[$command->name] = $command->handler;
            }
        }

        // Register the shared notification router.
        $this->connection->onNotification(
            'session.event',
            function (array $params): void {
                $sid = $params['sessionId'] ?? null;
                if ($sid !== null && $sid !== $this->sessionId) {
                    return;
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
     * Send a prompt to this session (non-blocking).
     *
     * @return string messageId assigned by the server.
     */
    public function send(MessageOptions|string $options): string
    {
        $this->assertConnected();

        if (is_string($options)) {
            $options = new MessageOptions(prompt: $options);
        }

        $params = ['sessionId' => $this->sessionId, 'prompt' => $options->prompt];

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
     * Send a prompt and block until the session becomes idle.
     *
     * @param float $timeout Maximum seconds to wait.
     *
     * @return SessionEvent|null The last assistant message, or null if none arrived.
     *
     * @throws SessionException    on `session.error` events.
     * @throws ConnectionException on timeout.
     */
    public function sendAndWait(
        MessageOptions|string $options,
        float $timeout = 60.0,
    ): ?SessionEvent {
        $lastAssistantMessage = null;
        $idle  = false;
        $error = null;

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
     * Block until the session becomes idle.
     *
     * @throws ConnectionException on timeout.
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
     * @return callable Unsubscribe callback.
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
     * @param callable(SessionEvent): void $handler
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
    // Permission / user-input / elicitation handler registration
    // =========================================================================

    public function registerPermissionHandler(callable $handler): void
    {
        $this->permissionHandler = $handler;
    }

    /**
     * Register a handler for synchronous user-input requests.
     *
     * Signature: `callable(array $request, array $context): string`
     */
    public function registerUserInputHandler(callable $handler): void
    {
        $this->userInputHandler = $handler;
    }

    /**
     * Register a handler for UI elicitation form requests.
     *
     * Signature: `callable(array $request, array $context): array`
     * Must return `['action' => 'accept', 'content' => [...]]` or `['action' => 'decline']`.
     */
    public function registerElicitationHandler(callable $handler): void
    {
        $this->elicitationHandler = $handler;
    }

    /**
     * Returns a permission handler that approves all requests.
     */
    public static function approveAll(): callable
    {
        return static fn () => ['kind' => 'approved'];
    }

    // =========================================================================
    // Session management (disconnect / pump)
    // =========================================================================

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
            // Best-effort.
        }
    }

    public function pump(float $timeout = 0.1): void
    {
        $this->connection->pump($timeout);
    }

    // =========================================================================
    // Model
    // =========================================================================

    /** @return array<string,mixed> Current model metadata. */
    public function getCurrentModel(): array
    {
        return $this->rpc('session.model.getCurrent', []);
    }

    /**
     * Switch to a different model mid-session.
     *
     * @return array<string,mixed>
     */
    public function switchModel(string $modelId, ?string $reasoningEffort = null): array
    {
        $params = ['modelId' => $modelId];
        if ($reasoningEffort !== null) {
            $params['reasoningEffort'] = $reasoningEffort;
        }
        return $this->rpc('session.model.switchTo', $params);
    }

    // =========================================================================
    // Mode (interactive / plan / autopilot)
    // =========================================================================

    /**
     * Get the current agent mode.
     *
     * @return array{mode: string}
     */
    public function getMode(): array
    {
        return $this->rpc('session.mode.get', []);
    }

    /**
     * Set the agent mode.
     *
     * @param string $mode "interactive" | "plan" | "autopilot"
     */
    public function setMode(string $mode): void
    {
        $this->rpc('session.mode.set', ['mode' => $mode]);
    }

    // =========================================================================
    // Session name
    // =========================================================================

    /** @return array{name: string|null} */
    public function getName(): array
    {
        return $this->rpc('session.name.get', []);
    }

    public function setName(string $name): void
    {
        $this->rpc('session.name.set', ['name' => $name]);
    }

    // =========================================================================
    // Plan file
    // =========================================================================

    /** @return array{content: string|null} */
    public function getPlan(): array
    {
        return $this->rpc('session.plan.read', []);
    }

    public function updatePlan(string $content): void
    {
        $this->rpc('session.plan.update', ['content' => $content]);
    }

    public function deletePlan(): void
    {
        $this->rpc('session.plan.delete', []);
    }

    // =========================================================================
    // Workspaces
    // =========================================================================

    /** @return array<string,mixed> */
    public function getWorkspace(): array
    {
        return $this->rpc('session.workspaces.getWorkspace', []);
    }

    /** @return array{files: list<string>} */
    public function listWorkspaceFiles(): array
    {
        return $this->rpc('session.workspaces.listFiles', []);
    }

    /** @return array{content: string} */
    public function readWorkspaceFile(string $path): array
    {
        return $this->rpc('session.workspaces.readFile', ['path' => $path]);
    }

    public function createWorkspaceFile(string $path, string $content): void
    {
        $this->rpc('session.workspaces.createFile', ['path' => $path, 'content' => $content]);
    }

    // =========================================================================
    // Instructions
    // =========================================================================

    /** @return array<string,mixed> */
    public function getInstructionSources(): array
    {
        return $this->rpc('session.instructions.getSources', []);
    }

    // =========================================================================
    // History
    // =========================================================================

    /** @return array<string,mixed> */
    public function compactHistory(): array
    {
        return $this->rpc('session.history.compact', []);
    }

    /**
     * Truncate conversation history up to (not including) the given event ID.
     *
     * @return array<string,mixed>
     */
    public function truncateHistory(string $toEventId): array
    {
        return $this->rpc('session.history.truncate', ['toEventId' => $toEventId]);
    }

    // =========================================================================
    // Usage metrics
    // =========================================================================

    /** @return array<string,mixed> */
    public function getUsageMetrics(): array
    {
        return $this->rpc('session.usage.getMetrics', []);
    }

    // =========================================================================
    // Shell
    // =========================================================================

    /**
     * Execute a shell command inside the session's working directory.
     *
     * @param string|list<string> $command  Command string or argv array.
     * @param string|null         $cwd      Override working directory.
     * @param int                 $timeoutMs Maximum milliseconds to wait.
     *
     * @return array{processId: string, exitCode: int|null, stdout: string, stderr: string}
     */
    public function execShell(string|array $command, ?string $cwd = null, int $timeoutMs = 30000): array
    {
        $params = [
            'command'   => is_array($command) ? implode(' ', $command) : $command,
            'timeoutMs' => $timeoutMs,
        ];
        if ($cwd !== null) {
            $params['cwd'] = $cwd;
        }
        return $this->rpc('session.shell.exec', $params);
    }

    /**
     * Send a signal to a running shell process.
     *
     * @return array<string,mixed>
     */
    public function killShell(string $processId, string $signal = 'SIGTERM'): array
    {
        return $this->rpc('session.shell.kill', [
            'processId' => $processId,
            'signal'    => $signal,
        ]);
    }

    // =========================================================================
    // Log
    // =========================================================================

    /**
     * Write a log entry to the session transcript.
     *
     * @param string $message  Log message.
     * @param string $level    "info" | "warning" | "error".
     *
     * @return array<string,mixed>
     */
    public function log(string $message, string $level = 'info'): array
    {
        return $this->rpc('session.log', ['message' => $message, 'level' => $level]);
    }

    // =========================================================================
    // Agent
    // =========================================================================

    /** @return array<string,mixed> */
    public function listAgents(): array
    {
        return $this->rpc('session.agent.list', []);
    }

    /** @return array<string,mixed> */
    public function getCurrentAgent(): array
    {
        return $this->rpc('session.agent.getCurrent', []);
    }

    /**
     * Select a named agent for this session.
     *
     * @return array<string,mixed>
     */
    public function selectAgent(string $name): array
    {
        return $this->rpc('session.agent.select', ['name' => $name]);
    }

    public function deselectAgent(): void
    {
        $this->rpc('session.agent.deselect', []);
    }

    /** @return array<string,mixed> */
    public function reloadAgents(): array
    {
        return $this->rpc('session.agent.reload', []);
    }

    // =========================================================================
    // Skills
    // =========================================================================

    /** @return array<string,mixed> */
    public function listSkills(): array
    {
        return $this->rpc('session.skills.list', []);
    }

    public function enableSkill(string $name): void
    {
        $this->rpc('session.skills.enable', ['name' => $name]);
    }

    public function disableSkill(string $name): void
    {
        $this->rpc('session.skills.disable', ['name' => $name]);
    }

    public function reloadSkills(): void
    {
        $this->rpc('session.skills.reload', []);
    }

    // =========================================================================
    // MCP servers (session-level)
    // =========================================================================

    /** @return array<string,mixed> */
    public function listMcpServers(): array
    {
        return $this->rpc('session.mcp.list', []);
    }

    public function enableMcpServer(string $serverName): void
    {
        $this->rpc('session.mcp.enable', ['serverName' => $serverName]);
    }

    public function disableMcpServer(string $serverName): void
    {
        $this->rpc('session.mcp.disable', ['serverName' => $serverName]);
    }

    public function reloadMcpServers(): void
    {
        $this->rpc('session.mcp.reload', []);
    }

    // =========================================================================
    // Extensions
    // =========================================================================

    /** @return array<string,mixed> */
    public function listExtensions(): array
    {
        return $this->rpc('session.extensions.list', []);
    }

    public function enableExtension(string $id): void
    {
        $this->rpc('session.extensions.enable', ['id' => $id]);
    }

    public function disableExtension(string $id): void
    {
        $this->rpc('session.extensions.disable', ['id' => $id]);
    }

    public function reloadExtensions(): void
    {
        $this->rpc('session.extensions.reload', []);
    }

    // =========================================================================
    // Plugins
    // =========================================================================

    /** @return array<string,mixed> */
    public function listPlugins(): array
    {
        return $this->rpc('session.plugins.list', []);
    }

    // =========================================================================
    // Messages (conversation history)
    // =========================================================================

    /** @return list<array<string,mixed>> */
    public function getMessages(): array
    {
        /** @var array<string,mixed> $result */
        $result = $this->rpc('session.messages.get', []);
        return (array) ($result['messages'] ?? []);
    }

    // =========================================================================
    // UI / Elicitation
    // =========================================================================

    /**
     * Proactively send an elicitation request to the user.
     *
     * @param array<string,mixed> $requestedSchema  JSON-Schema describing the form.
     * @return array<string,mixed>
     */
    public function elicitate(string $message, array $requestedSchema): array
    {
        return $this->rpc('session.ui.elicitation', [
            'message'         => $message,
            'requestedSchema' => $requestedSchema,
        ]);
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Make a session-scoped RPC call (automatically injects sessionId).
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function rpc(string $method, array $extra): array
    {
        $this->assertConnected();
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest($method, array_merge(
            ['sessionId' => $this->sessionId],
            $extra,
        ));
        return $result;
    }

    private function pumpUntilCondition(callable $condition, float $timeout, string $message): void
    {
        $deadline = microtime(true) + $timeout;

        while (!$condition()) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new ConnectionException($message);
            }
            $this->connection->pump(min($remaining, 0.1));
        }
    }

    private function dispatchEvent(SessionEvent $event): void
    {
        $this->handleBroadcastEvent($event);

        foreach ($this->typedEventHandlers[$event->type] ?? [] as $handler) {
            try {
                $handler($event);
            } catch (\Throwable) {
            }
        }

        foreach ($this->eventHandlers as $handler) {
            try {
                $handler($event);
            } catch (\Throwable) {
            }
        }
    }

    private function handleBroadcastEvent(SessionEvent $event): void
    {
        match ($event->type) {
            SessionEvent::TYPE_PERMISSION_REQUESTED    => $this->handlePermissionRequest($event),
            SessionEvent::TYPE_EXTERNAL_TOOL_REQUESTED => $this->handleToolCall($event),
            SessionEvent::TYPE_COMMAND_EXECUTE         => $this->handleCommandExecute($event),
            SessionEvent::TYPE_USER_INPUT_REQUESTED    => $this->handleUserInputRequest($event),
            SessionEvent::TYPE_ELICITATION_REQUESTED   => $this->handleElicitationRequest($event),
            default                                    => null,
        };
    }

    private function handlePermissionRequest(SessionEvent $event): void
    {
        $requestId      = (string) ($event->data['requestId']         ?? '');
        $permRequest    = (array)  ($event->data['permissionRequest'] ?? []);
        $resolvedByHook = (bool)   ($event->data['resolvedByHook']    ?? false);

        if ($resolvedByHook || $requestId === '') {
            return;
        }

        $handler = $this->permissionHandler ?? static fn () => ['kind' => 'approved'];

        try {
            $result = $handler($permRequest, ['sessionId' => $this->sessionId]);
        } catch (\Throwable) {
            $result = ['kind' => 'denied-no-approval-rule-and-could-not-request-from-user'];
        }

        try {
            $this->connection->sendRequest(
                'session.permissions.handlePendingPermissionRequest',
                ['sessionId' => $this->sessionId, 'requestId' => $requestId, 'result' => $result]
            );
        } catch (\Throwable) {
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
                $raw === null     => '',
                is_string($raw)   => $raw,
                is_array($raw)    => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                default           => (string) $raw,
            };

            $this->connection->sendRequest(
                'session.tools.handlePendingToolCall',
                ['sessionId' => $this->sessionId, 'requestId' => $requestId, 'result' => $result]
            );
        } catch (\Throwable $e) {
            try {
                $this->connection->sendRequest(
                    'session.tools.handlePendingToolCall',
                    ['sessionId' => $this->sessionId, 'requestId' => $requestId, 'error' => $e->getMessage()]
                );
            } catch (\Throwable) {
            }
        }
    }

    private function handleCommandExecute(SessionEvent $event): void
    {
        $requestId   = (string) ($event->data['requestId']   ?? '');
        $commandName = (string) ($event->data['commandName'] ?? '');
        $command     = (string) ($event->data['command']     ?? '');
        $args        = (string) ($event->data['args']        ?? '');

        if ($requestId === '' || $commandName === '') {
            return;
        }

        $handler = $this->commandHandlers[$commandName] ?? null;
        if ($handler === null) {
            return;
        }

        try {
            $handler($command, $args, ['sessionId' => $this->sessionId]);
            $this->connection->sendRequest(
                'session.commands.handlePendingCommand',
                ['sessionId' => $this->sessionId, 'requestId' => $requestId, 'result' => ['success' => true]]
            );
        } catch (\Throwable $e) {
            try {
                $this->connection->sendRequest(
                    'session.commands.handlePendingCommand',
                    ['sessionId' => $this->sessionId, 'requestId' => $requestId, 'error' => $e->getMessage()]
                );
            } catch (\Throwable) {
            }
        }
    }

    private function handleUserInputRequest(SessionEvent $event): void
    {
        $requestId = (string) ($event->data['requestId'] ?? '');

        if ($requestId === '' || $this->userInputHandler === null) {
            return;
        }

        try {
            $input = ($this->userInputHandler)($event->data, ['sessionId' => $this->sessionId]);
            $this->connection->sendRequest(
                'session.ui.handlePendingUserInput',
                ['sessionId' => $this->sessionId, 'requestId' => $requestId, 'input' => (string) $input]
            );
        } catch (\Throwable $e) {
            try {
                $this->connection->sendRequest(
                    'session.ui.handlePendingUserInput',
                    ['sessionId' => $this->sessionId, 'requestId' => $requestId, 'error' => $e->getMessage()]
                );
            } catch (\Throwable) {
            }
        }
    }

    private function handleElicitationRequest(SessionEvent $event): void
    {
        $requestId = (string) ($event->data['requestId'] ?? '');

        if ($requestId === '' || $this->elicitationHandler === null) {
            return;
        }

        try {
            $result = ($this->elicitationHandler)($event->data, ['sessionId' => $this->sessionId]);
            $this->connection->sendRequest(
                'session.ui.handlePendingElicitation',
                ['sessionId' => $this->sessionId, 'requestId' => $requestId, 'result' => $result]
            );
        } catch (\Throwable $e) {
            try {
                $this->connection->sendRequest(
                    'session.ui.handlePendingElicitation',
                    ['sessionId' => $this->sessionId, 'requestId' => $requestId,
                     'result' => ['action' => 'cancel', 'error' => $e->getMessage()]]
                );
            } catch (\Throwable) {
            }
        }
    }

    private function assertConnected(): void
    {
        if ($this->disconnected) {
            throw new SessionException("Session {$this->sessionId} has been disconnected.");
        }
    }
}
