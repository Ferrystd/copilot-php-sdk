<?php

/**
 * GitHub Copilot PHP SDK — Custom Tool Sample
 *
 * Demonstrates registering a custom tool ("get_weather") that the model
 * can call during a session.
 *
 * Usage:
 *   php samples/custom_tool.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Github\Copilot\V0\CopilotClient;
use Github\Copilot\V0\CopilotSession;
use Github\Copilot\V0\Types\MessageOptions;
use Github\Copilot\V0\Types\SessionConfig;
use Github\Copilot\V0\Types\Tool;

// ─────────────────────────────────────────────────────────────────────────────
// Define a custom tool
// ─────────────────────────────────────────────────────────────────────────────
$weatherTool = new Tool(
    name:        'get_weather',
    description: 'Returns the current simulated weather for a given city.',
    parameters:  [
        'type'       => 'object',
        'properties' => [
            'city' => [
                'type'        => 'string',
                'description' => 'Name of the city to query',
            ],
        ],
        'required'   => ['city'],
    ],
    handler: function (array $args): string {
        // In a real app you would call a weather API here.
        $city = $args['city'] ?? 'unknown';
        return "Weather in {$city}: 22°C, partly cloudy.";
    },
    skipPermission: true, // no permission prompt for this tool
);

// ─────────────────────────────────────────────────────────────────────────────
// Start client and create session with the tool
// ─────────────────────────────────────────────────────────────────────────────
$client = new CopilotClient();

$session = $client->createSession(new SessionConfig(
    model:               'gpt-4.1',
    tools:               [$weatherTool],
    onPermissionRequest: CopilotSession::approveAll(),
));

// ─────────────────────────────────────────────────────────────────────────────
// Ask a question that requires the weather tool
// ─────────────────────────────────────────────────────────────────────────────
echo "Asking about the weather...\n\n";

$response = $session->sendAndWait(
    new MessageOptions("What's the weather like in Tokyo and Paris right now?"),
    timeout: 60.0,
);

echo "Copilot: " . ($response?->getAssistantContent() ?? '(no response)') . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Clean up
// ─────────────────────────────────────────────────────────────────────────────
$session->disconnect();
$client->stop();
