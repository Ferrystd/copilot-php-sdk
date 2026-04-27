<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Types;

/**
 * Configuration for a new or resumed Copilot session.
 */
final class SessionConfig
{
    /**
     * @param string|null    $sessionId         Custom session ID (auto-generated UUID when null).
     * @param string|null    $model             Model identifier (e.g. "gpt-4.1", "claude-sonnet-4.5").
     * @param list<Tool>     $tools             Custom tools exposed to the model.
     * @param callable|null  $onPermissionRequest  Handler called before tool execution.
     *                                             Signature: (array $request, array $context): array
     *                                             Must return ['kind' => 'approved'] or a denial object.
     * @param callable|null  $onEvent           Wildcard event handler for all session events.
     *                                             Signature: (array $event): void
     * @param string|null    $workingDirectory  Working directory for the session.
     * @param string|null    $reasoningEffort   Reasoning effort: "low","medium","high","xhigh".
     * @param array<string,mixed>|null $systemMessage  System message customisation.
     * @param array<string,mixed>|null $provider       Custom BYOK provider configuration.
     */
    public function __construct(
        public readonly ?string   $sessionId         = null,
        public readonly ?string   $model             = null,
        public readonly array     $tools             = [],
        public readonly mixed     $onPermissionRequest = null,
        public readonly mixed     $onEvent           = null,
        public readonly ?string   $workingDirectory  = null,
        public readonly ?string   $reasoningEffort   = null,
        public readonly ?array    $systemMessage     = null,
        public readonly ?array    $provider          = null,
    ) {
    }
}
