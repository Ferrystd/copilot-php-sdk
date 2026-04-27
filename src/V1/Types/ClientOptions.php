<?php

declare(strict_types=1);

namespace Github\Copilot\V1\Types;

/**
 * Options for creating a {@see \Github\Copilot\V1\CopilotClient}.
 *
 * Identical to the V0 version; re-declared here so each version namespace
 * is fully self-contained.
 */
final class ClientOptions
{
    /**
     * @param string|null          $cliPath         Path to the Copilot CLI executable.
     * @param list<string>         $cliArgs         Extra arguments prepended to CLI invocation.
     * @param string|null          $cliUrl          TCP URL of an existing CLI server.
     * @param string|null          $cwd             Working directory for the spawned CLI process.
     * @param bool                 $useStdio        Use stdio instead of TCP when spawning CLI.
     * @param int                  $port            TCP port (0 = random).
     * @param string               $logLevel        CLI log level.
     * @param bool                 $autoStart       Automatically start on first use.
     * @param string|null          $githubToken     GitHub PAT.
     * @param bool|null            $useLoggedInUser Use stored OAuth token.
     * @param array<string,string>|null $env        Environment for the CLI process.
     * @param float                $connectTimeout  Seconds to wait when connecting to an external server.
     */
    public function __construct(
        public readonly ?string $cliPath         = null,
        public readonly array   $cliArgs         = [],
        public readonly ?string $cliUrl          = null,
        public readonly ?string $cwd             = null,
        public readonly bool    $useStdio        = true,
        public readonly int     $port            = 0,
        public readonly string  $logLevel        = 'info',
        public readonly bool    $autoStart       = true,
        public readonly ?string $githubToken     = null,
        public readonly ?bool   $useLoggedInUser = null,
        public readonly ?array  $env             = null,
        public readonly float   $connectTimeout  = 10.0,
    ) {
        if ($this->cliUrl !== null && $this->cliPath !== null) {
            throw new \InvalidArgumentException('cliUrl and cliPath are mutually exclusive.');
        }
    }
}
