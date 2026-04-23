<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Types;

/**
 * Options for creating a {@see \Github\Copilot\V0\CopilotClient}.
 */
final class ClientOptions
{
    /**
     * @param string|null   $cliPath         Path to the Copilot CLI executable.
     *                                        Defaults to the COPILOT_CLI_PATH env var, then 'copilot'.
     * @param list<string>  $cliArgs         Extra arguments prepended to CLI invocation.
     * @param string|null   $cliUrl          URL of an existing CLI server to connect to via TCP.
     *                                        Mutually exclusive with $cliPath.
     * @param string|null   $cwd             Working directory for the spawned CLI process.
     * @param bool          $useStdio        Use stdio (instead of TCP) when spawning CLI. Default true.
     * @param int           $port            TCP port when spawning CLI in TCP mode (0 = random).
     * @param string        $logLevel        Log level passed to the CLI ("none","error","warning","info","debug","all").
     * @param bool          $autoStart       Automatically start the connection on first session create.
     * @param string|null   $githubToken     GitHub personal-access token for authentication.
     * @param bool|null     $useLoggedInUser Whether to use the CLI's stored OAuth token. Defaults to true
     *                                        unless $githubToken is set.
     * @param array<string,string>|null $env  Environment variables passed to the CLI process.
     * @param float         $connectTimeout  Seconds to wait when connecting to an external server.
     */
    public function __construct(
        public readonly ?string $cliPath          = null,
        public readonly array   $cliArgs          = [],
        public readonly ?string $cliUrl           = null,
        public readonly ?string $cwd              = null,
        public readonly bool    $useStdio         = true,
        public readonly int     $port             = 0,
        public readonly string  $logLevel         = 'info',
        public readonly bool    $autoStart        = true,
        public readonly ?string $githubToken      = null,
        public readonly ?bool   $useLoggedInUser  = null,
        public readonly ?array  $env              = null,
        public readonly float   $connectTimeout   = 10.0,
    ) {
        if ($this->cliUrl !== null && $this->cliPath !== null) {
            throw new \InvalidArgumentException(
                'cliUrl and cliPath are mutually exclusive.'
            );
        }
    }
}
