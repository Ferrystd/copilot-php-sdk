<?php

declare(strict_types=1);

namespace Github\Copilot\V1\Types;

/**
 * Information about a single Copilot model.
 */
final class ModelInfo
{
    /**
     * @param string              $id
     * @param string              $name
     * @param array<string,mixed> $capabilities
     * @param list<string>        $supportedReasoningEfforts
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array  $capabilities              = [],
        public readonly array  $supportedReasoningEfforts = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id:                        (string) ($data['id']   ?? ''),
            name:                      (string) ($data['name'] ?? ''),
            capabilities:              (array)  ($data['capabilities'] ?? []),
            supportedReasoningEfforts: (array)  ($data['supportedReasoningEfforts'] ?? []),
        );
    }
}
