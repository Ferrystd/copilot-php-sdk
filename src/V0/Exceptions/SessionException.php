<?php

declare(strict_types=1);

namespace Github\Copilot\V0\Exceptions;

/**
 * Thrown when a session-level operation fails (e.g. session not found, session error event).
 */
class SessionException extends CopilotException
{
}
