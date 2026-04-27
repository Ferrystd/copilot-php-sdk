<?php

declare(strict_types=1);

namespace Github\Copilot\V1\Types;

/**
 * A custom tool exposed to the Copilot model.
 */
final class Tool
{
    /**
     * @param string               $name                 Tool identifier.
     * @param string               $description          Human-readable description.
     * @param array<string,mixed>|null $parameters       JSON-Schema for input parameters.
     * @param callable|null        $handler              Invoked when the model calls this tool.
     * @param bool                 $skipPermission       Skip permission prompt for this tool.
     * @param bool                 $overridesBuiltInTool When true, replaces a CLI built-in of the same name.
     */
    public function __construct(
        public readonly string  $name,
        public readonly string  $description,
        public readonly ?array  $parameters          = null,
        public readonly mixed   $handler             = null,
        public readonly bool    $skipPermission      = false,
        public readonly bool    $overridesBuiltInTool = false,
    ) {
    }
}
