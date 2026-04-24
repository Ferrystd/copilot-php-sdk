<?php

declare(strict_types=1);

namespace Github\Copilot\V1\Exceptions;

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

    public function getRpcCode(): int
    {
        return $this->rpcCode;
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
