<?php

declare(strict_types=1);

namespace Github\Copilot\V1\Types;

/**
 * Configuration for creating a new Copilot session (V1).
 *
 * Extends the V0 shape with commands, user-input/elicitation handlers,
 * MCP server configuration, tool filtering, and agent customisation.
 */
final class SessionConfig
{
    /**
     * @param string|null               $sessionId              Custom session ID (auto-UUID when null).
     * @param string|null               $model                  Model identifier.
     * @param list<Tool>                $tools                  Custom tools.
     * @param list<Command>             $commands               Custom slash-commands.
     * @param callable|null             $onPermissionRequest    Permission handler.
     * @param callable|null             $onEvent                Wildcard event handler.
     * @param callable|null             $onUserInputRequest     User-input request handler.
     *                                                          Signature: (array $request, array $context): string
     * @param callable|null             $onElicitationRequest   Elicitation handler.
     *                                                          Signature: (array $request, array $context): array
     * @param string|null               $workingDirectory       Session working directory.
     * @param string|null               $reasoningEffort        "low"|"medium"|"high"|"xhigh".
     * @param array<string,mixed>|null  $systemMessage          System message overrides.
     * @param array<string,mixed>|null  $provider               BYOK provider configuration.
     * @param list<string>|null         $availableTools         Allowlist of tool names.
     * @param list<string>|null         $excludedTools          Denylist of tool names.
     * @param list<McpServerConfig>     $mcpServers             MCP servers to attach.
     * @param bool|null                 $streaming              Enable streaming events.
     * @param string|null               $configDir              Explicit config directory.
     * @param bool|null                 $enableConfigDiscovery  Auto-discover local configs.
     * @param list<string>              $skillDirectories       Extra skill directory paths.
     * @param list<string>              $disabledSkills         Skills to disable at session start.
     * @param bool|null                 $infiniteSessions       Infinite session mode.
     * @param list<array<string,mixed>> $customAgents           Custom agent definitions.
     * @param string|null               $defaultAgent           Default agent name.
     * @param string|null               $agent                  Override which agent handles this session.
     * @param string|null               $clientName             Human-readable name for this client.
     */
    public function __construct(
        public readonly ?string   $sessionId             = null,
        public readonly ?string   $model                 = null,
        public readonly array     $tools                 = [],
        public readonly array     $commands              = [],
        public readonly mixed     $onPermissionRequest   = null,
        public readonly mixed     $onEvent               = null,
        public readonly mixed     $onUserInputRequest    = null,
        public readonly mixed     $onElicitationRequest  = null,
        public readonly ?string   $workingDirectory      = null,
        public readonly ?string   $reasoningEffort       = null,
        public readonly ?array    $systemMessage         = null,
        public readonly ?array    $provider              = null,
        public readonly ?array    $availableTools        = null,
        public readonly ?array    $excludedTools         = null,
        public readonly array     $mcpServers            = [],
        public readonly ?bool     $streaming             = null,
        public readonly ?string   $configDir             = null,
        public readonly ?bool     $enableConfigDiscovery = null,
        public readonly array     $skillDirectories      = [],
        public readonly array     $disabledSkills        = [],
        public readonly ?bool     $infiniteSessions      = null,
        public readonly array     $customAgents          = [],
        public readonly ?string   $defaultAgent          = null,
        public readonly ?string   $agent                 = null,
        public readonly ?string   $clientName            = null,
    ) {
    }
}
