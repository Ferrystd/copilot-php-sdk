<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Types;

/**
 * A custom tool that the session exposes to the Copilot model.
 *
 * When the model calls the tool the provided $handler callable is invoked with
 * the decoded arguments and must return a string (or array that will be
 * JSON-encoded) to send back to the model.
 *
 * @phpstan-type ToolHandler callable(mixed $args): (string|array<mixed>|null)
 */
final class Tool
{
    /**
     * @param string                $name        Tool identifier (e.g. "get_weather").
     * @param string                $description Human-readable description of what the tool does.
     * @param array<string,mixed>|null $parameters JSON-Schema object describing the input parameters.
     * @param callable|null         $handler     Invoked when the model calls this tool.
     *                                            Signature: (mixed $args): string|array|null
     * @param bool                  $skipPermission Whether to skip the permission prompt for this tool.
     */
    public function __construct(
        public readonly string  $name,
        public readonly string  $description,
        public readonly ?array  $parameters   = null,
        public readonly mixed   $handler      = null,
        public readonly bool    $skipPermission = false,
    ) {
    }
}
