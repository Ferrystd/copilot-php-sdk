<?php

/**
 * GitHub Copilot PHP SDK — Basic Chat Sample
 *
 * This sample demonstrates a simple back-and-forth conversation with the
 * Copilot model using the V0 PHP SDK.
 *
 * Prerequisites:
 *   1. composer install (from the repo root)
 *   2. Copilot CLI installed and authenticated: `copilot auth login`
 *
 * Usage:
 *   php samples/chat.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Github\Copilot\V0\CopilotClient;
use Github\Copilot\V0\CopilotSession;
use Github\Copilot\V0\Events\SessionEvent;
use Github\Copilot\V0\Types\MessageOptions;
use Github\Copilot\V0\Types\SessionConfig;

// ─────────────────────────────────────────────────────────────────────────────
// 1. Boot the client (auto-starts the CLI server via stdio)
// ─────────────────────────────────────────────────────────────────────────────
$client = new CopilotClient();

// ─────────────────────────────────────────────────────────────────────────────
// 2. Open a session
// ─────────────────────────────────────────────────────────────────────────────
$session = $client->createSession(new SessionConfig(
    model:               'gpt-4.1',
    onPermissionRequest: CopilotSession::approveAll(),
));

// Stream each assistant token to stdout in real-time.
$session->onType('assistant.message_delta', function (SessionEvent $event): void {
    echo $event->data['delta'] ?? '';
    flush();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Simple interactive REPL
// ─────────────────────────────────────────────────────────────────────────────
echo "Copilot PHP SDK — interactive chat (type 'exit' to quit)\n";
echo str_repeat('─', 60) . "\n\n";

while (true) {
    echo 'You: ';
    $line = fgets(STDIN);

    if ($line === false) {
        break; // EOF
    }

    $line = trim($line);

    if ($line === '') {
        continue;
    }

    if (in_array($line, ['exit', 'quit', 'q'], strict: true)) {
        break;
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

// ─────────────────────────────────────────────────────────────────────────────
// 4. Clean up
// ─────────────────────────────────────────────────────────────────────────────
$session->disconnect();
$client->stop();

echo "\nBye!\n";
