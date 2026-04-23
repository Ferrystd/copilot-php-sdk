<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Types;

/**
 * Information about a single Copilot model.
 */
final class ModelInfo
{
    /**
     * @param string                    $id          Model identifier (e.g. "gpt-4.1").
     * @param string                    $name        Human-readable display name.
     * @param array<string,mixed>       $capabilities  Model capability flags.
     * @param list<string>              $supportedReasoningEfforts  Supported effort levels, if any.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array  $capabilities                = [],
        public readonly array  $supportedReasoningEfforts   = [],
    ) {
    }

    /**
     * Create a ModelInfo from a raw array (as returned by the RPC response).
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:                         (string) ($data['id']   ?? ''),
            name:                       (string) ($data['name'] ?? ''),
            capabilities:               (array)  ($data['capabilities'] ?? []),
            supportedReasoningEfforts:  (array)  ($data['supportedReasoningEfforts'] ?? []),
        );
    }
}
