<?php

declare(strict_types=1);

namespace Github\Copilot\V1\Events;

/**
 * Represents a single session event received from the Copilot CLI.
 *
 * V1 extends the V0 base event with richer convenience accessors for all
 * known event types (streaming deltas, tool calls, shell output, etc.).
 */
final class SessionEvent
{
    // -------------------------------------------------------------------------
    // Known event type constants
    // -------------------------------------------------------------------------

    /** Final assistant message with complete content. */
    public const TYPE_ASSISTANT_MESSAGE       = 'assistant.message';
    /** Incremental streaming token delta. */
    public const TYPE_ASSISTANT_MESSAGE_DELTA = 'assistant.message_delta';
    /** Session has finished processing and is idle. */
    public const TYPE_SESSION_IDLE            = 'session.idle';
    /** Session encountered an error. */
    public const TYPE_SESSION_ERROR           = 'session.error';
    /** Session started. */
    public const TYPE_SESSION_START           = 'session.start';
    /** Session stopped. */
    public const TYPE_SESSION_STOP            = 'session.stop';
    /** External (custom) tool is being called. */
    public const TYPE_EXTERNAL_TOOL_REQUESTED = 'external_tool.requested';
    /** Tool call completed. */
    public const TYPE_TOOL_COMPLETED          = 'tool.completed';
    /** Tool call started (e.g. a built-in tool). */
    public const TYPE_TOOL_STARTED            = 'tool.started';
    /** Permission approval is required. */
    public const TYPE_PERMISSION_REQUESTED    = 'permission.requested';
    /** A slash-command needs to be executed. */
    public const TYPE_COMMAND_EXECUTE         = 'command.execute';
    /** User-input is requested (synchronous). */
    public const TYPE_USER_INPUT_REQUESTED    = 'user_input.requested';
    /** UI elicitation form is requested. */
    public const TYPE_ELICITATION_REQUESTED   = 'elicitation.requested';
    /** Shell process produced output. */
    public const TYPE_SHELL_OUTPUT            = 'shell.output';
    /** Session capabilities changed. */
    public const TYPE_CAPABILITIES_CHANGED    = 'capabilities.changed';
    /** Agent changed mid-session. */
    public const TYPE_AGENT_CHANGED           = 'agent.changed';

    // -------------------------------------------------------------------------

    /**
     * @param string              $type    The event type identifier.
     * @param array<string,mixed> $data    The event payload.
     * @param string              $eventId Unique identifier for this event (monotonically increasing).
     */
    public function __construct(
        public readonly string $type,
        public readonly array  $data,
        public readonly string $eventId = '',
    ) {
    }

    /** Construct from a raw notification params array. */
    public static function fromNotificationParams(array $params): self
    {
        return new self(
            type:    (string) ($params['type']    ?? ''),
            data:    (array)  ($params['data']    ?? []),
            eventId: (string) ($params['eventId'] ?? ''),
        );
    }

    // =========================================================================
    // Type predicates
    // =========================================================================

    public function isAssistantMessage(): bool
    {
        return $this->type === self::TYPE_ASSISTANT_MESSAGE;
    }

    public function isAssistantMessageDelta(): bool
    {
        return $this->type === self::TYPE_ASSISTANT_MESSAGE_DELTA;
    }

    public function isIdle(): bool
    {
        return $this->type === self::TYPE_SESSION_IDLE;
    }

    public function isError(): bool
    {
        return $this->type === self::TYPE_SESSION_ERROR;
    }

    public function isToolCall(): bool
    {
        return $this->type === self::TYPE_EXTERNAL_TOOL_REQUESTED;
    }

    public function isPermissionRequest(): bool
    {
        return $this->type === self::TYPE_PERMISSION_REQUESTED;
    }

    public function isCommandExecute(): bool
    {
        return $this->type === self::TYPE_COMMAND_EXECUTE;
    }

    public function isElicitationRequest(): bool
    {
        return $this->type === self::TYPE_ELICITATION_REQUESTED;
    }

    public function isUserInputRequest(): bool
    {
        return $this->type === self::TYPE_USER_INPUT_REQUESTED;
    }

    public function isShellOutput(): bool
    {
        return $this->type === self::TYPE_SHELL_OUTPUT;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The assistant message content (full, final). Null for non-message events.
     */
    public function getAssistantContent(): ?string
    {
        if (!$this->isAssistantMessage()) {
            return null;
        }
        return isset($this->data['content']) ? (string) $this->data['content'] : null;
    }

    /**
     * The streaming delta text for `assistant.message_delta` events.
     */
    public function getDelta(): ?string
    {
        if (!$this->isAssistantMessageDelta()) {
            return null;
        }
        return isset($this->data['delta']) ? (string) $this->data['delta'] : null;
    }

    /**
     * The error message for `session.error` events.
     */
    public function getErrorMessage(): ?string
    {
        if (!$this->isError()) {
            return null;
        }
        return isset($this->data['message']) ? (string) $this->data['message'] : null;
    }

    /**
     * The name of the tool that was called (`external_tool.requested`).
     */
    public function getToolName(): ?string
    {
        return isset($this->data['toolName']) ? (string) $this->data['toolName'] : null;
    }

    /**
     * The decoded tool arguments (`external_tool.requested`).
     */
    public function getToolArguments(): mixed
    {
        return $this->data['arguments'] ?? null;
    }

    /**
     * The request ID used to send a reply back (`external_tool.requested`,
     * `permission.requested`, `command.execute`, `elicitation.requested`,
     * `user_input.requested`).
     */
    public function getRequestId(): ?string
    {
        return isset($this->data['requestId']) ? (string) $this->data['requestId'] : null;
    }

    /**
     * Shell output text (`shell.output` events).
     */
    public function getShellOutput(): ?string
    {
        return isset($this->data['output']) ? (string) $this->data['output'] : null;
    }

    /**
     * Shell process ID (`shell.output` events).
     */
    public function getShellProcessId(): ?string
    {
        return isset($this->data['processId']) ? (string) $this->data['processId'] : null;
    }
}
