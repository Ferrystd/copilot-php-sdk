<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Transport;

use Github\Copilot\V0\Exceptions\ConnectionException;

/**
 * Stdio transport — communicates with a child process via stdin/stdout pipes.
 *
 * The underlying process is started with {@see proc_open}. The caller passes
 * the open pipes produced by proc_open; this class only handles I/O, not
 * process lifecycle (which is managed by {@see \Github\Copilot\V0\CopilotClient}).
 */
final class StdioTransport implements TransportInterface
{
    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    private bool $closed = false;

    /**
     * @param resource $stdin  Writable pipe to the child process stdin.
     * @param resource $stdout Readable pipe from the child process stdout.
     */
    public function __construct($stdin, $stdout)
    {
        if (!is_resource($stdin) || !is_resource($stdout)) {
            throw new \InvalidArgumentException('stdin and stdout must be valid resources');
        }

        $this->stdin  = $stdin;
        $this->stdout = $stdout;

        // Make stdout non-blocking so we can use stream_select.
        stream_set_blocking($this->stdout, false);
    }

    public function write(string $data): void
    {
        $this->assertOpen();

        $total   = strlen($data);
        $written = 0;

        while ($written < $total) {
            $chunk = fwrite($this->stdin, substr($data, $written));
            if ($chunk === false) {
                throw new ConnectionException('Failed to write to CLI stdin');
            }
            $written += $chunk;
        }
    }

    public function read(int $length): string
    {
        $this->assertOpen();

        $buffer = '';

        while (strlen($buffer) < $length) {
            $remaining = $length - strlen($buffer);

            // Wait up to 30 s for more data before giving up.
            if (!$this->isReadable(30.0)) {
                throw new ConnectionException(
                    'Timed out waiting for data from CLI stdout'
                );
            }

            $chunk = fread($this->stdout, $remaining);

            if ($chunk === false || ($chunk === '' && feof($this->stdout))) {
                $this->closed = true;
                throw new ConnectionException('CLI process closed stdout unexpectedly');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    public function isReadable(float $timeout = 0.0): bool
    {
        if (!is_resource($this->stdout)) {
            return false;
        }

        $read    = [$this->stdout];
        $write   = null;
        $except  = null;
        $sec     = (int) $timeout;
        $usec    = (int)(($timeout - $sec) * 1_000_000);

        $result = stream_select($read, $write, $except, $sec, $usec);

        return $result !== false && $result > 0;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        if (is_resource($this->stdout)) {
            fclose($this->stdout);
        }
    }

    public function isOpen(): bool
    {
        return !$this->closed
            && is_resource($this->stdin)
            && is_resource($this->stdout);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function assertOpen(): void
    {
        if (!$this->isOpen()) {
            throw new ConnectionException('Transport is closed');
        }
    }
}
