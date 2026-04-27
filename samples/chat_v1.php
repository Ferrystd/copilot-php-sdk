<?php

/**
 * GitHub Copilot PHP SDK — V1 Basic Chat Sample
 *
 * Demonstrates the V1 namespace usage and a simple feature only available in
 * V1 APIs (mode switching).
 *
 * Usage:
 *   php samples/chat_v1.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Github\Copilot\V1\CopilotClient;
use Github\Copilot\V1\CopilotSession;
use Github\Copilot\V1\Events\SessionEvent;
use Github\Copilot\V1\Types\MessageOptions;
use Github\Copilot\V1\Types\SessionConfig;

$client = new CopilotClient();
$session = $client->createSession(new SessionConfig(
    model: 'gpt-4.1',
    onPermissionRequest: CopilotSession::approveAll(),
));

// V1-only style: set explicit agent mode before sending.
$session->setMode('interactive');

$session->onType(SessionEvent::TYPE_ASSISTANT_MESSAGE_DELTA, function (SessionEvent $event): void {
    echo $event->getDelta() ?? '';
    flush();
});

echo "Copilot PHP SDK V1 — interactive chat (type 'exit' to quit)\n";
echo str_repeat('─', 60) . "\n\n";

while (true) {
    echo 'You: ';
    $line = fgets(STDIN);

    if ($line === false) {
        break;
    }

    $line = trim($line);

    if ($line === '' || in_array($line, ['exit', 'quit', 'q'], true)) {
        if ($line !== '') {
            break;
        }
        continue;
    }

    echo 'Copilot: ';

    try {
        $response = $session->sendAndWait(new MessageOptions($line), timeout: 120.0);
        if ($response !== null) {
            echo $response->getAssistantContent();
        }
    } catch (\Throwable $e) {
        echo "[Error] {$e->getMessage()}";
    }

    echo "\n\n";
}

$session->disconnect();
$client->stop();

echo "\nBye!\n";
