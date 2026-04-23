# GitHub Copilot PHP SDK

PHP SDK for programmatic control of GitHub Copilot CLI via JSON-RPC.

> **Note:** This SDK is in public preview and may change in breaking ways.

## Requirements

- PHP 8.1 or higher
- [GitHub Copilot CLI](https://docs.github.com/en/copilot/how-tos/set-up/install-copilot-cli) installed and authenticated

## Installation

```bash
composer require github/copilot-php-sdk
```

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use Github\Copilot\V0\CopilotClient;
use Github\Copilot\V0\CopilotSession;
use Github\Copilot\V0\Events\SessionEvent;
use Github\Copilot\V0\Types\SessionConfig;
use Github\Copilot\V0\Types\MessageOptions;

$client  = new CopilotClient();
$session = $client->createSession(new SessionConfig(
    model: 'gpt-4.1',
    onPermissionRequest: CopilotSession::approveAll(),
));

$response = $session->sendAndWait(new MessageOptions('What is 2+2?'));
echo $response?->getAssistantContent() . PHP_EOL;

$session->disconnect();
$client->stop();
```

## Namespace Versioning

| Namespace | Status |
|-----------|--------|
| `Github\Copilot\V0` | Current |

Each version namespace is a separate, self-contained API surface. Breaking
changes are introduced in a new version namespace, allowing existing code
to continue working unchanged.

## API Reference

### `CopilotClient`

The main entry point.

```php
use Github\Copilot\V0\CopilotClient;
use Github\Copilot\V0\Types\ClientOptions;

// Default (spawns CLI via stdio)
$client = new CopilotClient();

// Custom options
$client = new CopilotClient(new ClientOptions(
    cliPath:       '/usr/local/bin/copilot',
    logLevel:      'debug',
    githubToken:   getenv('GITHUB_TOKEN') ?: null,
));

// Connect to an existing external CLI server
$client = new CopilotClient(new ClientOptions(
    cliUrl: 'localhost:3000',
));
```

#### Methods

| Method | Description |
|--------|-------------|
| `start(): void` | Connect to / spawn the CLI server. |
| `stop(): array` | Gracefully stop all sessions and the server. Returns any errors. |
| `forceStop(): void` | Forcefully terminate without cleanup. |
| `createSession(SessionConfig): CopilotSession` | Open a new session. |
| `resumeSession(string, SessionConfig): CopilotSession` | Resume a previous session by ID. |
| `ping(string): array` | Ping the server. |
| `listModels(): ModelInfo[]` | List available models. |
| `getQuota(): array` | Get account quota information. |
| `getState(): string` | Current connection state. |

### `CopilotSession`

```php
use Github\Copilot\V0\Types\SessionConfig;
use Github\Copilot\V0\Types\MessageOptions;
use Github\Copilot\V0\Events\SessionEvent;

$session = $client->createSession(new SessionConfig(
    model:              'gpt-4.1',
    onPermissionRequest: CopilotSession::approveAll(),
));
```

#### Sending messages

```php
// Non-blocking — events arrive via on()
$messageId = $session->send(new MessageOptions('Hello!'));

// Blocking — waits until session.idle
$event = $session->sendAndWait('What is 2+2?', timeout: 30.0);
echo $event?->getAssistantContent();
```

#### Handling events

```php
// All events
$unsubscribe = $session->on(function (SessionEvent $event): void {
    if ($event->isAssistantMessage()) {
        echo $event->getAssistantContent();
    }
});

// Specific event type
$session->onType('session.idle', function (SessionEvent $event): void {
    echo "Session is idle\n";
});

// Unsubscribe later
$unsubscribe();
```

#### Session management

```php
// Get current model
$model = $session->getCurrentModel();

// Switch model mid-session
$session->switchModel('claude-sonnet-4.5');

// Get conversation history
$messages = $session->getMessages();

// Pump message loop manually
$session->pump(0.1); // wait up to 100 ms

// Clean up
$session->disconnect();
```

### Custom Tools

```php
use Github\Copilot\V0\Types\Tool;
use Github\Copilot\V0\Types\SessionConfig;

$weatherTool = new Tool(
    name:        'get_weather',
    description: 'Get current weather for a city',
    parameters:  [
        'type'       => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'description' => 'City name'],
        ],
        'required'   => ['city'],
    ],
    handler: function (array $args): string {
        $city = $args['city'];
        // Call your weather API here
        return "It's 22°C and sunny in {$city}.";
    },
);

$session = $client->createSession(new SessionConfig(
    model:               'gpt-4.1',
    tools:               [$weatherTool],
    onPermissionRequest: CopilotSession::approveAll(),
));
```

### Permission Handling

```php
use Github\Copilot\V0\Types\SessionConfig;

// Approve all (use in trusted environments only)
$session = $client->createSession(new SessionConfig(
    onPermissionRequest: CopilotSession::approveAll(),
));

// Custom handler
$session = $client->createSession(new SessionConfig(
    onPermissionRequest: function (array $request, array $context): array {
        // Log the request
        error_log("Permission requested: " . json_encode($request));

        // Approve only shell reads
        if (($request['kind'] ?? '') === 'read') {
            return ['kind' => 'approved'];
        }

        return ['kind' => 'denied-interactively-by-user', 'feedback' => 'Denied'];
    },
));
```

### BYOK (Bring Your Own Key)

```php
use Github\Copilot\V0\Types\SessionConfig;

$session = $client->createSession(new SessionConfig(
    model:    'gpt-4.1',
    provider: [
        'type'   => 'openai',
        'apiKey' => getenv('OPENAI_API_KEY'),
    ],
    onPermissionRequest: CopilotSession::approveAll(),
));
```

## Architecture

All SDK instances communicate with the Copilot CLI server via JSON-RPC 2.0,
using the same [LSP message framing](https://microsoft.github.io/language-server-protocol/specifications/lsp/3.17/specification/#headerPart) as VS Code:

```
Your PHP Application
        ↓
  CopilotClient
        ↓ JSON-RPC 2.0 over stdio/TCP
  Copilot CLI (server mode)
        ↓
  GitHub Copilot API
```

## Running the Tests

```bash
composer install
./vendor/bin/phpunit
```

## License

MIT
