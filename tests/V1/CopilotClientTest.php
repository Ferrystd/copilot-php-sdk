<?php

declare(strict_types=1);

namespace Github\Copilot\Tests\V1;

use Github\Copilot\V1\CopilotClient;
use Github\Copilot\V1\Exceptions\ConnectionException;
use Github\Copilot\V1\Types\ClientOptions;
use Github\Copilot\V1\Types\Command;
use Github\Copilot\V1\Types\McpServerConfig;
use Github\Copilot\V1\Types\ModelInfo;
use Github\Copilot\V1\Types\ResumeSessionConfig;
use Github\Copilot\V1\Types\SessionConfig;
use Github\Copilot\V1\Types\Tool;
use PHPUnit\Framework\TestCase;

final class CopilotClientTest extends TestCase
{
    public function testClientOptionsDefaultValues(): void
    {
        $options = new ClientOptions();

        self::assertNull($options->cliPath);
        self::assertNull($options->cliUrl);
        self::assertSame([], $options->cliArgs);
        self::assertTrue($options->useStdio);
        self::assertSame(0, $options->port);
        self::assertSame('info', $options->logLevel);
        self::assertTrue($options->autoStart);
        self::assertNull($options->githubToken);
        self::assertNull($options->useLoggedInUser);
        self::assertNull($options->env);
        self::assertEqualsWithDelta(10.0, $options->connectTimeout, 0.001);
    }

    public function testClientOptionsThrowsWhenBothCliPathAndCliUrlAreSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');

        new ClientOptions(
            cliPath: '/usr/bin/copilot',
            cliUrl: 'localhost:3000',
        );
    }

    public function testClientConstructorAcceptsNullAndArrayAndObject(): void
    {
        self::assertSame('disconnected', (new CopilotClient(null))->getState());
        self::assertSame('disconnected', (new CopilotClient(['autoStart' => false]))->getState());
        self::assertSame('disconnected', (new CopilotClient(new ClientOptions(autoStart: false)))->getState());
    }

    public function testStartThrowsConnectionExceptionWhenCliBinaryNotFound(): void
    {
        $client = new CopilotClient(new ClientOptions(
            cliPath: '/absolutely/nonexistent/copilot-cli-binary',
            autoStart: false,
        ));

        $this->expectException(ConnectionException::class);
        $client->start();
    }

    public function testCreateSessionThrowsWhenNotConnectedAndAutoStartDisabled(): void
    {
        $client = new CopilotClient(new ClientOptions(autoStart: false));

        $this->expectException(ConnectionException::class);
        $client->createSession(new SessionConfig());
    }

    public function testModelInfoFromArrayAndDefaults(): void
    {
        $model = ModelInfo::fromArray([
            'id' => 'gpt-4.1',
            'name' => 'GPT-4.1',
            'capabilities' => ['supports' => ['vision' => true]],
            'supportedReasoningEfforts' => ['low', 'medium', 'high'],
        ]);
        self::assertSame('gpt-4.1', $model->id);
        self::assertSame('GPT-4.1', $model->name);
        self::assertSame(['supports' => ['vision' => true]], $model->capabilities);
        self::assertSame(['low', 'medium', 'high'], $model->supportedReasoningEfforts);

        $defaults = ModelInfo::fromArray([]);
        self::assertSame('', $defaults->id);
        self::assertSame('', $defaults->name);
        self::assertSame([], $defaults->capabilities);
        self::assertSame([], $defaults->supportedReasoningEfforts);
    }

    public function testSessionConfigDefaultsAndValues(): void
    {
        $defaults = new SessionConfig();
        self::assertNull($defaults->sessionId);
        self::assertNull($defaults->model);
        self::assertSame([], $defaults->tools);
        self::assertSame([], $defaults->commands);
        self::assertNull($defaults->onPermissionRequest);
        self::assertNull($defaults->onUserInputRequest);
        self::assertNull($defaults->onElicitationRequest);
        self::assertSame([], $defaults->mcpServers);
        self::assertNull($defaults->clientName);

        $tool = new Tool('my_tool', 'tool');
        $command = new Command('hello', 'say hello');
        $mcp = new McpServerConfig(name: 'local-mcp', command: 'mcp-server');
        $permissionHandler = fn () => ['kind' => 'approved'];

        $config = new SessionConfig(
            sessionId: 'sid-1',
            model: 'claude-sonnet-4.5',
            tools: [$tool],
            commands: [$command],
            onPermissionRequest: $permissionHandler,
            workingDirectory: '/workspace',
            reasoningEffort: 'high',
            mcpServers: [$mcp],
            clientName: 'php-sdk-tests',
        );

        self::assertSame('sid-1', $config->sessionId);
        self::assertSame('claude-sonnet-4.5', $config->model);
        self::assertCount(1, $config->tools);
        self::assertCount(1, $config->commands);
        self::assertSame($permissionHandler, $config->onPermissionRequest);
        self::assertSame('/workspace', $config->workingDirectory);
        self::assertSame('high', $config->reasoningEffort);
        self::assertCount(1, $config->mcpServers);
        self::assertSame('php-sdk-tests', $config->clientName);
    }

    public function testResumeSessionConfigDefaultsAndValues(): void
    {
        $defaults = new ResumeSessionConfig();
        self::assertNull($defaults->model);
        self::assertSame([], $defaults->tools);
        self::assertSame([], $defaults->commands);
        self::assertFalse($defaults->disableResume);

        $config = new ResumeSessionConfig(
            model: 'gpt-4.1',
            disableResume: true,
        );

        self::assertSame('gpt-4.1', $config->model);
        self::assertTrue($config->disableResume);
    }

    public function testToolAndCommandDefaults(): void
    {
        $tool = new Tool('greet', 'Greet');
        self::assertSame('greet', $tool->name);
        self::assertSame('Greet', $tool->description);
        self::assertNull($tool->parameters);
        self::assertNull($tool->handler);
        self::assertFalse($tool->skipPermission);
        self::assertFalse($tool->overridesBuiltInTool);

        $command = new Command('fix', 'Fix issue');
        self::assertSame('fix', $command->name);
        self::assertSame('Fix issue', $command->description);
        self::assertNull($command->handler);
    }
}
