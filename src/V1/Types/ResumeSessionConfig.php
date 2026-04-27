<?php

declare(strict_types=1);

namespace Github\Copilot\V1\Types;

/**
 * Configuration for resuming a previously-created session.
 *
 * Like {@see SessionConfig} but with an extra `$disableResume` flag that
 * tells the CLI to start fresh rather than continuing from history.
 */
final class ResumeSessionConfig
{
    /**
     * @param string|null               $model
     * @param list<Tool>                $tools
     * @param list<Command>             $commands
     * @param callable|null             $onPermissionRequest
     * @param callable|null             $onEvent
     * @param callable|null             $onUserInputRequest
     * @param callable|null             $onElicitationRequest
     * @param string|null               $workingDirectory
     * @param string|null               $reasoningEffort
     * @param array<string,mixed>|null  $systemMessage
     * @param array<string,mixed>|null  $provider
     * @param list<string>|null         $availableTools
     * @param list<string>|null         $excludedTools
     * @param list<McpServerConfig>     $mcpServers
     * @param bool|null                 $streaming
     * @param string|null               $configDir
     * @param bool|null                 $enableConfigDiscovery
     * @param list<string>              $skillDirectories
     * @param list<string>              $disabledSkills
     * @param bool|null                 $infiniteSessions
     * @param list<array<string,mixed>> $customAgents
     * @param string|null               $defaultAgent
     * @param string|null               $agent
     * @param string|null               $clientName
     * @param bool                      $disableResume  When true, start a new session ignoring previous history.
     */
    public function __construct(
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
        public readonly bool      $disableResume         = false,
    ) {
    }
}
