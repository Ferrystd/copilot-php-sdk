<?php

declare(strict_types=1);

namespace Github\Copilot\V0;

use Github\Copilot\V0\Exceptions\ConnectionException;
use Github\Copilot\V0\Exceptions\RpcException;
use Github\Copilot\V0\JsonRpc\Connection;
use Github\Copilot\V0\Transport\StdioTransport;
use Github\Copilot\V0\Transport\TcpTransport;
use Github\Copilot\V0\Types\ClientOptions;
use Github\Copilot\V0\Types\ModelInfo;
use Github\Copilot\V0\Types\SessionConfig;

/**
 * Main client for interacting with the GitHub Copilot CLI via JSON-RPC.
 *
 * `CopilotClient` manages:
 *  - Optionally spawning the Copilot CLI process.
 *  - Establishing the JSON-RPC connection (stdio or TCP).
 *  - Creating and tracking {@see CopilotSession} instances.
 *
 * ## Minimum protocol version
 * The SDK refuses connections with servers that report a protocol version
 * below {@see MIN_PROTOCOL_VERSION}.
 *
 * ## Example
 *
 * ```php
 * use Github\Copilot\V0\CopilotClient;
 * use Github\Copilot\V0\Types\SessionConfig;
 * use Github\Copilot\V0\Types\MessageOptions;
 *
 * $client  = new CopilotClient();
 * $session = $client->createSession(new SessionConfig(model: 'gpt-4.1'));
 *
 * $response = $session->sendAndWait(new MessageOptions('What is 2+2?'));
 * echo $response?->getAssistantContent();
 *
 * $session->disconnect();
 * $client->stop();
 * ```
 */
final class CopilotClient
{
    /** Minimum protocol version this SDK can speak with. */
    private const MIN_PROTOCOL_VERSION = 2;

    /** This SDK's own protocol version (matches the reference implementations). */
    private const SDK_PROTOCOL_VERSION = 3;

    /** @var 'disconnected'|'connecting'|'connected'|'error' */
    private string $state = 'disconnected';

    private ?Connection $connection = null;

    /** @var resource|null  The proc_open process handle (stdio mode only). */
    private $process = null;

    /** @var array<string,CopilotSession>  Active sessions keyed by sessionId. */
    private array $sessions = [];

    /** Buffer for CLI stderr output (used in error messages). */
    private string $stderrBuffer = '';

    /** @var resource|null  stderr pipe of the CLI process. */
    private $stderrPipe = null;

    private readonly ClientOptions $options;

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * @param ClientOptions|array<string,mixed>|null $options
     *        Pass a {@see ClientOptions} instance, an associative array of the
     *        same property names, or null for all defaults.
     *
     * @throws \InvalidArgumentException for conflicting option combinations.
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
     * Start the CLI server (if needed) and establish the JSON-RPC connection.
     *
     * If the client is already connected this is a no-op.
     *
     * @throws ConnectionException if the connection cannot be established.
     * @throws RpcException        if the server reports an incompatible protocol.
     */
    public function start(): void
    {
        if ($this->state === 'connected') {
            return;
        }

        $this->state = 'connecting';

        try {
            if ($this->options->cliUrl !== null) {
                // --- Connect to an external server via TCP ---
                $transport = TcpTransport::fromUrl(
                    $this->options->cliUrl,
                    $this->options->connectTimeout
                );
                $transport->connect();
            } else {
                // --- Spawn the CLI process and use stdio ---
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
     * Gracefully stop all active sessions and the CLI server process.
     *
     * @return list<\Throwable>  Errors encountered during cleanup.
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
     * Forcefully terminate the CLI process without graceful session cleanup.
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
     * Create a new conversation session.
     *
     * If `autoStart` is true (the default) and the client is not yet connected,
     * {@see start()} is called automatically.
     *
     * @throws \InvalidArgumentException if required session config is missing.
     * @throws ConnectionException       on transport/startup failures.
     * @throws RpcException              if the server rejects the session creation.
     */
    public function createSession(SessionConfig|array|null $config = null): CopilotSession
    {
        if (is_array($config)) {
            $config = new SessionConfig(...$config);
        }
        $config ??= new SessionConfig();

        $this->ensureConnected();

        $sessionId = $config->sessionId ?? $this->generateUuid();

        $session = new CopilotSession($sessionId, $this->connection, $config->tools);

        if ($config->onPermissionRequest !== null) {
            $session->registerPermissionHandler($config->onPermissionRequest);
        } else {
            // Default: approve all.
            $session->registerPermissionHandler(CopilotSession::approveAll());
        }

        if ($config->onEvent !== null) {
            $session->on($config->onEvent);
        }

        $this->sessions[$sessionId] = $session;

        $params = $this->buildCreateSessionParams($sessionId, $config);

        try {
            $this->connection->sendRequest('session.create', $params);
        } catch (\Throwable $e) {
            unset($this->sessions[$sessionId]);
            throw $e;
        }

        return $session;
    }

    /**
     * Resume a previously-created session by its ID.
     *
     * @throws ConnectionException if not connected and autoStart fails.
     * @throws RpcException        if the server rejects the resume.
     */
    public function resumeSession(
        string $sessionId,
        SessionConfig|array|null $config = null,
    ): CopilotSession {
        if (is_array($config)) {
            $config = new SessionConfig(...$config);
        }
        $config ??= new SessionConfig();

        $this->ensureConnected();

        $session = new CopilotSession($sessionId, $this->connection, $config->tools);

        if ($config->onPermissionRequest !== null) {
            $session->registerPermissionHandler($config->onPermissionRequest);
        } else {
            $session->registerPermissionHandler(CopilotSession::approveAll());
        }

        if ($config->onEvent !== null) {
            $session->on($config->onEvent);
        }

        $this->sessions[$sessionId] = $session;

        $params = $this->buildCreateSessionParams($sessionId, $config);

        try {
            $this->connection->sendRequest('session.resume', $params);
        } catch (\Throwable $e) {
            unset($this->sessions[$sessionId]);
            throw $e;
        }

        return $session;
    }

    /**
     * Disconnect and remove a session from the internal registry.
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
    // Server-level RPC helpers
    // =========================================================================

    /**
     * Ping the CLI server to verify connectivity.
     *
     * @param string $message Optional message echoed back by the server.
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
     * Get quota information for the current account.
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

    /**
     * Get the current connection state.
     *
     * @return 'disconnected'|'connecting'|'connected'|'error'
     */
    public function getState(): string
    {
        return $this->state;
    }

    // =========================================================================
    // Internal — process / connection helpers
    // =========================================================================

    /**
     * Spawn the Copilot CLI process and return an stdio transport connected to it.
     *
     * @throws ConnectionException if the process cannot be started.
     */
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
            0 => ['pipe', 'r'],   // stdin  (we write to the process)
            1 => ['pipe', 'w'],   // stdout (we read from the process)
            2 => ['pipe', 'w'],   // stderr (captured for error reporting)
        ];

        $cwd  = $this->options->cwd ?? getcwd() ?: null;

        // Suppress the built-in PHP warning from proc_open when the binary
        // is not found; we convert it to a ConnectionException below.
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

    /**
     * Verify that the connected server speaks a compatible protocol version.
     *
     * @throws RpcException        if the server returns an error.
     * @throws ConnectionException if the protocol is too old.
     */
    private function verifyProtocolVersion(): void
    {
        /** @var array<string,mixed> $result */
        $result          = $this->connection->sendRequest('ping', []);
        $serverVersion   = (int) ($result['protocolVersion'] ?? 0);

        if ($serverVersion < self::MIN_PROTOCOL_VERSION) {
            throw new ConnectionException(
                "Copilot CLI server protocol version {$serverVersion} is too old; "
                . 'minimum required is ' . self::MIN_PROTOCOL_VERSION . '.'
            );
        }
    }

    /**
     * Ensure the client is connected, starting it if autoStart allows.
     */
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

    /**
     * Build the params array for session.create / session.resume.
     *
     * @return array<string,mixed>
     */
    private function buildCreateSessionParams(string $sessionId, SessionConfig $config): array
    {
        $params = [
            'sessionId'        => $sessionId,
            'requestPermission' => true,
            'sdkProtocolVersion' => self::SDK_PROTOCOL_VERSION,
        ];

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

        if ($config->tools !== []) {
            $params['tools'] = array_map(
                static fn ($tool) => [
                    'name'           => $tool->name,
                    'description'    => $tool->description,
                    'parameters'     => $tool->parameters,
                    'skipPermission' => $tool->skipPermission,
                ],
                $config->tools
            );
        }

        return $params;
    }

    /**
     * Kill the spawned CLI process (if any).
     */
    private function killProcess(bool $force = false): void
    {
        if ($this->stderrPipe !== null && is_resource($this->stderrPipe)) {
            // Drain remaining stderr.
            $this->stderrBuffer .= stream_get_contents($this->stderrPipe) ?: '';
            fclose($this->stderrPipe);
            $this->stderrPipe = null;
        }

        if ($this->process !== null) {
            if ($force) {
                // SIGKILL equivalent — only on Unix.
                if (function_exists('proc_terminate')) {
                    proc_terminate($this->process, 9);
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
    }

    /**
     * Generate a RFC-4122 v4 UUID without external dependencies.
     */
    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
