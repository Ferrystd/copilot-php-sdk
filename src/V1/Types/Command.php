<?php

declare(strict_types=1);

namespace Github\Copilot\V1\Types;

/**
 * A custom slash-command registered with the session.
 *
 * When the user types `/<name>` in the Copilot chat UI, the CLI fires a
 * `command.execute` event. The session dispatches it to the registered
 * $handler callable.
 *
 * Handler signature:
 *   callable(string $command, string $args, array $context): void
 */
final class Command
{
    /**
     * @param string        $name        Slash-command name without the leading "/".
     * @param string        $description Human-readable description shown in the UI.
     * @param callable|null $handler     Invoked when the command is executed.
     *                                   Signature: (string $command, string $args, array $context): void
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly mixed  $handler = null,
    ) {
    }
}
