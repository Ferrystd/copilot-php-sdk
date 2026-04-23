<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Exceptions;

/**
 * Thrown when a connection to the Copilot CLI server cannot be established
 * or is unexpectedly lost.
 */
class ConnectionException extends CopilotException
{
}
