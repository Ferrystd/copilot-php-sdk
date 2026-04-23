<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Exceptions;

/**
 * Thrown when a JSON-RPC request returns an error response.
 */
class RpcException extends CopilotException
{
    public function __construct(
        string $message,
        private readonly int $rpcCode = 0,
        private readonly mixed $data = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $rpcCode, $previous);
    }

    /**
     * Returns the JSON-RPC error code from the server response.
     */
    public function getRpcCode(): int
    {
        return $this->rpcCode;
    }

    /**
     * Returns optional additional error data from the server response.
     */
    public function getData(): mixed
    {
        return $this->data;
    }
}
