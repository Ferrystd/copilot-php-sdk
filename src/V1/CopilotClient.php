<?php

declare(strict_types=1);

namespace Github\Copilot\V1;

use Github\Copilot\V0\JsonRpc\Connection;
use Github\Copilot\V0\Transport\StdioTransport;
use Github\Copilot\V0\Transport\TcpTransport;
use Github\Copilot\V1\Exceptions\ConnectionException;
use Github\Copilot\V1\Exceptions\RpcException;
use Github\Copilot\V1\Types\ClientOptions;
use Github\Copilot\V1\Types\McpServerConfig;
use Github\Copilot\V1\Types\ModelInfo;
use Github\Copilot\V1\Types\ResumeSessionConfig;
use Github\Copilot\V1\Types\SessionConfig;

/**
 * Main entry point for the GitHub Copilot PHP SDK (V1).
 *
 * V1 extends the V0 client with:
 *   - `tools.list` — enumerate all available tools (model-aware)
 *   - `mcp.config.*` — manage server-level MCP configuration
 *   - `mcp.discover` — discover available MCP servers
 *   - `skills.discover` — scan project paths for custom skills
 *   - `skills.config.setDisabledSkills` — persist disabled skills list
 *   - `sessions.fork` — fork a session at a given event checkpoint
 *
 * All V0 functionality is also available (`ping`, `listModels`, `getQuota`,
 * `createSession`, `resumeSession`, `deleteSession`, `stop`, `forceStop`).
 *
 * ## Example
 *
 * ```php
 * use Github\Copilot\V1\CopilotClient;
 * use Github\Copilot\V1\CopilotSession;
 * use Github\Copilot\V1\Types\SessionConfig;
 *
 * $client  = new CopilotClient();
 * $session = $client->createSession(new SessionConfig(
 *     model:               'gpt-4.1',
 *     onPermissionRequest: CopilotSession::approveAll(),
 * ));
 *
 * $response = $session->sendAndWait('List available skills');
 * echo $response?->getAssistantContent();
 *
 * $session->disconnect();
 * $client->stop();
 * ```
 */
final class CopilotClient
{
    private const MIN_PROTOCOL_VERSION = 2;
    private const SDK_PROTOCOL_VERSION = 3;

    /** @var 'disconnected'|'connecting'|'connected'|'error' */
    private string $state = 'disconnected';

    private ?Connection $connection = null;

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stderrPipe = null;

    private string $stderrBuffer = '';

    /** @var array<string, CopilotSession> */
    private array $sessions = [];

    private readonly ClientOptions $options;

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * @param ClientOptions|array<string,mixed>|null $options
     */
    public function __construct(ClientOptions|array|null $options = null)
    {
        if (is_array($options)) {
            $options = new ClientOptions(...$options);
        }
        $this->options = $options ?? new ClientOptions();
    }

    // =========================================================================
    // Lifecycle
    // =========================================================================

    /**
     * Connect to / spawn the Copilot CLI server.
     *
     * @throws ConnectionException on transport failures.
     * @throws RpcException        on incompatible protocol.
     */
    public function start(): void
    {
        if ($this->state === 'connected') {
            return;
        }

        $this->state = 'connecting';

        try {
            if ($this->options->cliUrl !== null) {
                $transport = TcpTransport::fromUrl(
                    $this->options->cliUrl,
                    $this->options->connectTimeout
                );
                $transport->connect();
            } else {
                $transport = $this->spawnCliProcess();
            }

            $this->connection = new Connection($transport);
            $this->verifyProtocolVersion();
            $this->state = 'connected';
        } catch (\Throwable $e) {
            $this->state = 'error';
            throw $e instanceof ConnectionException || $e instanceof RpcException
                ? $e
                : new ConnectionException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Gracefully stop all sessions and the CLI server.
     *
     * @return list<\Throwable>
     */
    public function stop(): array
    {
        $errors = [];

        foreach ($this->sessions as $session) {
            try {
                $session->disconnect();
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }
        $this->sessions = [];

        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
            $this->connection = null;
        }

        $this->killProcess();
        $this->state = 'disconnected';

        return $errors;
    }

    /**
     * Force-kill the process without graceful cleanup.
     */
    public function forceStop(): void
    {
        $this->sessions = [];

        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (\Throwable) {
            }
            $this->connection = null;
        }

        $this->killProcess(force: true);
        $this->state = 'disconnected';
    }

    // =========================================================================
    // Session management
    // =========================================================================

    /**
     * Create a new Copilot session.
     *
     * @throws ConnectionException on transport/startup failures.
     * @throws RpcException        if the server rejects the session.
     */
    public function createSession(SessionConfig|array|null $config = null): CopilotSession
    {
        if (is_array($config)) {
            $config = new SessionConfig(...$config);
        }
        $config ??= new SessionConfig();

        $this->ensureConnected();

        $sessionId = $config->sessionId ?? $this->generateUuid();

        $session = new CopilotSession(
            $sessionId,
            $this->connection,
            $config->tools,
            $config->commands,
        );

        if ($config->onPermissionRequest !== null) {
            $session->registerPermissionHandler($config->onPermissionRequest);
        } else {
            $session->registerPermissionHandler(CopilotSession::approveAll());
        }
        if ($config->onUserInputRequest !== null) {
            $session->registerUserInputHandler($config->onUserInputRequest);
        }
        if ($config->onElicitationRequest !== null) {
            $session->registerElicitationHandler($config->onElicitationRequest);
        }
        if ($config->onEvent !== null) {
            $session->on($config->onEvent);
        }

        $this->sessions[$sessionId] = $session;

        try {
            $this->connection->sendRequest(
                'session.create',
                $this->buildCreateParams($sessionId, $config)
            );
        } catch (\Throwable $e) {
            unset($this->sessions[$sessionId]);
            throw $e;
        }

        return $session;
    }

    /**
     * Resume a previously-created session.
     *
     * @param string                            $sessionId
     * @param ResumeSessionConfig|array|null    $config
     *
     * @throws ConnectionException on transport failures.
     * @throws RpcException        if the server rejects the resume.
     */
    public function resumeSession(
        string $sessionId,
        ResumeSessionConfig|array|null $config = null,
    ): CopilotSession {
        if (is_array($config)) {
            $config = new ResumeSessionConfig(...$config);
        }
        $config ??= new ResumeSessionConfig();

        $this->ensureConnected();

        $session = new CopilotSession(
            $sessionId,
            $this->connection,
            $config->tools,
            $config->commands,
        );

        if ($config->onPermissionRequest !== null) {
            $session->registerPermissionHandler($config->onPermissionRequest);
        } else {
            $session->registerPermissionHandler(CopilotSession::approveAll());
        }
        if ($config->onUserInputRequest !== null) {
            $session->registerUserInputHandler($config->onUserInputRequest);
        }
        if ($config->onElicitationRequest !== null) {
            $session->registerElicitationHandler($config->onElicitationRequest);
        }
        if ($config->onEvent !== null) {
            $session->on($config->onEvent);
        }

        $this->sessions[$sessionId] = $session;

        $params = $this->buildResumeParams($sessionId, $config);

        try {
            $this->connection->sendRequest('session.resume', $params);
        } catch (\Throwable $e) {
            unset($this->sessions[$sessionId]);
            throw $e;
        }

        return $session;
    }

    /**
     * Disconnect and untrack a session.
     */
    public function deleteSession(string $sessionId): void
    {
        $session = $this->sessions[$sessionId] ?? null;
        if ($session !== null) {
            $session->disconnect();
            unset($this->sessions[$sessionId]);
        }
    }

    // =========================================================================
    // Server-level RPC
    // =========================================================================

    /**
     * Ping the server.
     *
     * @return array{message: string, timestamp: int, protocolVersion: int}
     */
    public function ping(string $message = ''): array
    {
        $this->ensureConnected();
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest('ping', ['message' => $message]);
        return $result;
    }

    /**
     * List all available models.
     *
     * @return list<ModelInfo>
     */
    public function listModels(): array
    {
        $this->ensureConnected();
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest('models.list', []);
        return array_map(
            static fn (array $m) => ModelInfo::fromArray($m),
            (array) ($result['models'] ?? [])
        );
    }

    /**
     * List all available tools (optionally filtered by model).
     *
     * @return array<string,mixed>
     */
    public function listTools(?string $model = null): array
    {
        $this->ensureConnected();
        $params = $model !== null ? ['model' => $model] : [];
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest('tools.list', $params);
        return $result;
    }

    /**
     * Get account quota information.
     *
     * @return array<string,mixed>
     */
    public function getQuota(): array
    {
        $this->ensureConnected();
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest('account.getQuota', []);
        return $result;
    }

    // =========================================================================
    // MCP server configuration (server-level)
    // =========================================================================

    /**
     * List configured MCP servers.
     *
     * @return array<string,mixed>
     */
    public function mcpConfigList(): array
    {
        $this->ensureConnected();
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest('mcp.config.list', []);
        return $result;
    }

    /**
     * Add a new MCP server to the global configuration.
     */
    public function mcpConfigAdd(McpServerConfig $config): void
    {
        $this->ensureConnected();
        $this->connection->sendRequest('mcp.config.add', $config->toArray());
    }

    /**
     * Update an existing MCP server configuration.
     */
    public function mcpConfigUpdate(McpServerConfig $config): void
    {
        $this->ensureConnected();
        $this->connection->sendRequest('mcp.config.update', $config->toArray());
    }

    /**
     * Remove an MCP server from the global configuration.
     */
    public function mcpConfigRemove(string $name): void
    {
        $this->ensureConnected();
        $this->connection->sendRequest('mcp.config.remove', ['name' => $name]);
    }

    /**
     * Discover available MCP servers in the given working directory.
     *
     * @return array<string,mixed>
     */
    public function mcpDiscover(?string $workingDirectory = null): array
    {
        $this->ensureConnected();
        $params = [];
        if ($workingDirectory !== null) {
            $params['workingDirectory'] = $workingDirectory;
        }
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest('mcp.discover', $params);
        return $result;
    }

    // =========================================================================
    // Skills (server-level)
    // =========================================================================

    /**
     * Persist the disabled-skills list in the global config.
     *
     * @param list<string> $skills
     */
    public function setDisabledSkills(array $skills): void
    {
        $this->ensureConnected();
        $this->connection->sendRequest(
            'skills.config.setDisabledSkills',
            ['skills' => $skills]
        );
    }

    /**
     * Discover skills in project directories.
     *
     * @param list<string> $projectPaths
     * @param list<string> $skillDirectories
     *
     * @return array<string,mixed>
     */
    public function discoverSkills(array $projectPaths = [], array $skillDirectories = []): array
    {
        $this->ensureConnected();
        $params = [];
        if ($projectPaths !== []) {
            $params['projectPaths'] = $projectPaths;
        }
        if ($skillDirectories !== []) {
            $params['skillDirectories'] = $skillDirectories;
        }
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest('skills.discover', $params);
        return $result;
    }

    // =========================================================================
    // Session forking
    // =========================================================================

    /**
     * Fork an existing session, optionally truncating to a specific event.
     *
     * @param string|null $toEventId  Truncate to this event (exclusive).
     *
     * @return array{sessionId: string}
     */
    public function forkSession(string $sessionId, ?string $toEventId = null): array
    {
        $this->ensureConnected();
        $params = ['sessionId' => $sessionId];
        if ($toEventId !== null) {
            $params['toEventId'] = $toEventId;
        }
        /** @var array<string,mixed> $result */
        $result = $this->connection->sendRequest('sessions.fork', $params);
        return $result;
    }

    // =========================================================================
    // State
    // =========================================================================

    /** @return 'disconnected'|'connecting'|'connected'|'error' */
    public function getState(): string
    {
        return $this->state;
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function spawnCliProcess(): StdioTransport
    {
        $cliPath = $this->options->cliPath
            ?? getenv('COPILOT_CLI_PATH')
            ?: 'copilot';

        $args = array_merge($this->options->cliArgs, [
            'serve',
            '--log-level', $this->options->logLevel,
        ]);

        if (!$this->options->useStdio) {
            $args[] = '--port';
            $args[] = (string) $this->options->port;
        }

        $cmd = array_merge([$cliPath], $args);
        $env = $this->options->env ?? getenv() ?: [];

        if ($this->options->githubToken !== null) {
            $env['GITHUB_TOKEN'] = $this->options->githubToken;
        }
        if ($this->options->useLoggedInUser === false) {
            $env['COPILOT_USE_LOGGED_IN_USER'] = '0';
        }

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = $this->options->cwd ?? getcwd() ?: null;

        set_error_handler(static function (): bool { return true; });
        try {
            $proc = proc_open($cmd, $descriptor, $pipes, $cwd, $env);
        } finally {
            restore_error_handler();
        }

        if ($proc === false || !isset($pipes[0], $pipes[1], $pipes[2])) {
            throw new ConnectionException(
                'Failed to spawn Copilot CLI process: ' . implode(' ', $cmd)
            );
        }

        $this->process    = $proc;
        $this->stderrPipe = $pipes[2];
        stream_set_blocking($this->stderrPipe, false);

        return new StdioTransport($pipes[0], $pipes[1]);
    }

    private function verifyProtocolVersion(): void
    {
        /** @var array<string,mixed> $result */
        $result        = $this->connection->sendRequest('ping', []);
        $serverVersion = (int) ($result['protocolVersion'] ?? 0);

        if ($serverVersion < self::MIN_PROTOCOL_VERSION) {
            throw new ConnectionException(
                "Copilot CLI server protocol version {$serverVersion} is too old; "
                . 'minimum required is ' . self::MIN_PROTOCOL_VERSION . '.'
            );
        }
    }

    private function ensureConnected(): void
    {
        if ($this->state === 'connected') {
            return;
        }

        if ($this->options->autoStart) {
            $this->start();
            return;
        }

        throw new ConnectionException(
            'CopilotClient is not connected. Call start() first, or set autoStart=true.'
        );
    }

    /** @return array<string,mixed> */
    private function buildCreateParams(string $sessionId, SessionConfig $config): array
    {
        $params = [
            'sessionId'          => $sessionId,
            'requestPermission'  => true,
            'requestUserInput'   => $config->onUserInputRequest !== null,
            'requestElicitation' => $config->onElicitationRequest !== null,
            'sdkProtocolVersion' => self::SDK_PROTOCOL_VERSION,
        ];

        $this->fillCommonSessionParams($params, $config);

        return $params;
    }

    /** @return array<string,mixed> */
    private function buildResumeParams(string $sessionId, ResumeSessionConfig $config): array
    {
        $params = [
            'sessionId'          => $sessionId,
            'requestPermission'  => true,
            'requestUserInput'   => $config->onUserInputRequest !== null,
            'requestElicitation' => $config->onElicitationRequest !== null,
            'sdkProtocolVersion' => self::SDK_PROTOCOL_VERSION,
        ];

        if ($config->disableResume) {
            $params['disableResume'] = true;
        }

        $this->fillCommonSessionParams($params, $config);

        return $params;
    }

    /**
     * Populate shared session params from either a SessionConfig or ResumeSessionConfig.
     *
     * @param array<string,mixed>                       $params  Modified in place.
     * @param SessionConfig|ResumeSessionConfig         $config
     */
    private function fillCommonSessionParams(array &$params, SessionConfig|ResumeSessionConfig $config): void
    {
        if ($config->model !== null) {
            $params['model'] = $config->model;
        }
        if ($config->reasoningEffort !== null) {
            $params['reasoningEffort'] = $config->reasoningEffort;
        }
        if ($config->workingDirectory !== null) {
            $params['workingDirectory'] = $config->workingDirectory;
        }
        if ($config->systemMessage !== null) {
            $params['systemMessage'] = $config->systemMessage;
        }
        if ($config->provider !== null) {
            $params['provider'] = $config->provider;
        }
        if ($config->availableTools !== null) {
            $params['availableTools'] = $config->availableTools;
        }
        if ($config->excludedTools !== null) {
            $params['excludedTools'] = $config->excludedTools;
        }
        if ($config->streaming !== null) {
            $params['streaming'] = $config->streaming;
        }
        if ($config->configDir !== null) {
            $params['configDir'] = $config->configDir;
        }
        if ($config->enableConfigDiscovery !== null) {
            $params['enableConfigDiscovery'] = $config->enableConfigDiscovery;
        }
        if ($config->skillDirectories !== []) {
            $params['skillDirectories'] = $config->skillDirectories;
        }
        if ($config->disabledSkills !== []) {
            $params['disabledSkills'] = $config->disabledSkills;
        }
        if ($config->infiniteSessions !== null) {
            $params['infiniteSessions'] = $config->infiniteSessions;
        }
        if ($config->customAgents !== []) {
            $params['customAgents'] = $config->customAgents;
        }
        if ($config->defaultAgent !== null) {
            $params['defaultAgent'] = $config->defaultAgent;
        }
        if ($config->agent !== null) {
            $params['agent'] = $config->agent;
        }
        if ($config->clientName !== null) {
            $params['clientName'] = $config->clientName;
        }

        if ($config->tools !== []) {
            $params['tools'] = array_map(
                static fn ($t) => [
                    'name'                => $t->name,
                    'description'         => $t->description,
                    'parameters'          => $t->parameters,
                    'skipPermission'      => $t->skipPermission,
                    'overridesBuiltInTool' => $t->overridesBuiltInTool,
                ],
                $config->tools
            );
        }

        if ($config->commands !== []) {
            $params['commands'] = array_map(
                static fn ($c) => ['name' => $c->name, 'description' => $c->description],
                $config->commands
            );
        }

        if ($config->mcpServers !== []) {
            $params['mcpServers'] = array_map(
                static fn ($s) => $s->toArray(),
                $config->mcpServers
            );
        }
    }

    private function killProcess(bool $force = false): void
    {
        if ($this->stderrPipe !== null && is_resource($this->stderrPipe)) {
            $this->stderrBuffer .= stream_get_contents($this->stderrPipe) ?: '';
            fclose($this->stderrPipe);
            $this->stderrPipe = null;
        }

        if ($this->process !== null) {
            if ($force && function_exists('proc_terminate')) {
                proc_terminate($this->process, 9);
            }
            proc_close($this->process);
            $this->process = null;
        }
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
