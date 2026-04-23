<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Types;

/**
 * Options for sending a message to a session.
 */
final class MessageOptions
{
    /**
     * @param string                   $prompt      The user message / prompt to send.
     * @param list<array<string,mixed>> $attachments Optional file or context attachments.
     * @param string|null              $mode        Agent mode override ("interactive","plan","autopilot").
     */
    public function __construct(
        public readonly string  $prompt,
        public readonly array   $attachments = [],
        public readonly ?string $mode        = null,
    ) {
    }
}
