<?php

declare(strict_types=1);

namespace Github\Copilot\V1\Types;

/**
 * MCP (Model Context Protocol) server configuration.
 *
 * Supports two kinds of MCP servers:
 *   - `local`  — a locally-spawned process communicating over stdio.
 *   - `http`   — a remote server reachable over HTTP/SSE.
 */
final class McpServerConfig
{
    public const KIND_LOCAL = 'local';
    public const KIND_HTTP  = 'http';

    /**
     * @param string               $name         Unique server identifier.
     * @param string               $kind         'local' or 'http'.
     * @param string               $command      Executable path (local kind only).
     * @param list<string>         $args         Arguments for the executable (local kind only).
     * @param array<string,string> $env          Extra environment variables (local kind only).
     * @param string|null          $url          Server URL (http kind only).
     * @param array<string,string> $headers      HTTP headers (http kind only).
     * @param bool                 $disabled     Whether this server is currently disabled.
     */
    public function __construct(
        public readonly string  $name,
        public readonly string  $kind    = self::KIND_LOCAL,
        public readonly string  $command = '',
        public readonly array   $args    = [],
        public readonly array   $env     = [],
        public readonly ?string $url     = null,
        public readonly array   $headers = [],
        public readonly bool    $disabled = false,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name:     (string) ($data['name']    ?? ''),
            kind:     (string) ($data['kind']    ?? self::KIND_LOCAL),
            command:  (string) ($data['command'] ?? ''),
            args:     (array)  ($data['args']    ?? []),
            env:      (array)  ($data['env']     ?? []),
            url:      isset($data['url']) ? (string) $data['url'] : null,
            headers:  (array)  ($data['headers'] ?? []),
            disabled: (bool)   ($data['disabled'] ?? false),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $result = ['name' => $this->name, 'kind' => $this->kind];

        if ($this->kind === self::KIND_LOCAL) {
            $result['command'] = $this->command;
            if ($this->args !== []) {
                $result['args'] = $this->args;
            }
            if ($this->env !== []) {
                $result['env'] = $this->env;
            }
        } else {
            $result['url'] = $this->url;
            if ($this->headers !== []) {
                $result['headers'] = $this->headers;
            }
        }

        if ($this->disabled) {
            $result['disabled'] = true;
        }

        return $result;
    }
}
