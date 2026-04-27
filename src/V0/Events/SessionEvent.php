<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Events;

/**
 * Represents a single session event received from the Copilot CLI.
 *
 * Events carry a typed `$type` string (e.g. "assistant.message", "session.idle")
 * and a free-form `$data` payload whose shape depends on the event type.
 */
final class SessionEvent
{
    /**
     * @param string              $type    The event type identifier.
     * @param array<string,mixed> $data    The event payload.
     * @param string              $eventId A unique identifier for this event.
     */
    public function __construct(
        public readonly string $type,
        public readonly array  $data,
        public readonly string $eventId = '',
    ) {
    }

    /**
     * Construct a SessionEvent from a raw notification payload.
     *
     * @param array<string,mixed> $params
     */
    public static function fromNotificationParams(array $params): self
    {
        return new self(
            type:    (string) ($params['type']    ?? ''),
            data:    (array)  ($params['data']    ?? []),
            eventId: (string) ($params['eventId'] ?? ''),
        );
    }

    /**
     * True when this event is the final assistant message.
     */
    public function isAssistantMessage(): bool
    {
        return $this->type === 'assistant.message';
    }

    /**
     * True when the session has become idle (the model has finished responding).
     */
    public function isIdle(): bool
    {
        return $this->type === 'session.idle';
    }

    /**
     * True when the session has encountered an error.
     */
    public function isError(): bool
    {
        return $this->type === 'session.error';
    }

    /**
     * Convenience: return the assistant message content, or null if not applicable.
     */
    public function getAssistantContent(): ?string
    {
        if (!$this->isAssistantMessage()) {
            return null;
        }
        return isset($this->data['content']) ? (string) $this->data['content'] : null;
    }

    /**
     * Convenience: return the error message, or null if not an error event.
     */
    public function getErrorMessage(): ?string
    {
        if (!$this->isError()) {
            return null;
        }
        return isset($this->data['message']) ? (string) $this->data['message'] : null;
    }
}
