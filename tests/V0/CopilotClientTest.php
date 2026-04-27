<?php

declare(strict_types=1);

namespace Github\Copilot\Tests\V0;

use Github\Copilot\V0\CopilotClient;
use Github\Copilot\V0\Exceptions\ConnectionException;
use Github\Copilot\V0\Types\ClientOptions;
use Github\Copilot\V0\Types\ModelInfo;
use Github\Copilot\V0\Types\SessionConfig;
use Github\Copilot\V0\Types\Tool;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CopilotClient options validation and type helpers.
 *
 * Note: tests that require a real Copilot CLI are integration tests and
 * are not included here. We only test pure-PHP logic.
 */
final class CopilotClientTest extends TestCase
{
    // =========================================================================
    // ClientOptions validation
    // =========================================================================

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
            cliUrl:  'localhost:3000',
        );
    }

    public function testClientOptionsAcceptsCliUrl(): void
    {
        $options = new ClientOptions(cliUrl: 'localhost:3000');
        self::assertSame('localhost:3000', $options->cliUrl);
    }

    // =========================================================================
    // CopilotClient constructor accepts different option formats
    // =========================================================================

    public function testClientConstructorAcceptsNull(): void
    {
        $client = new CopilotClient(null);
        self::assertSame('disconnected', $client->getState());
    }

    public function testClientConstructorAcceptsClientOptionsObject(): void
    {
        $options = new ClientOptions(autoStart: false);
        $client  = new CopilotClient($options);
        self::assertSame('disconnected', $client->getState());
    }

    public function testClientConstructorAcceptsArray(): void
    {
        $client = new CopilotClient(['autoStart' => false]);
        self::assertSame('disconnected', $client->getState());
    }

    // =========================================================================
    // getState()
    // =========================================================================

    public function testInitialStateIsDisconnected(): void
    {
        $client = new CopilotClient();
        self::assertSame('disconnected', $client->getState());
    }

    // =========================================================================
    // start() — requires no real CLI: test that it throws when CLI is absent
    // =========================================================================

    public function testStartThrowsConnectionExceptionWhenCliBinaryNotFound(): void
    {
        $client = new CopilotClient(new ClientOptions(
            cliPath:    '/absolutely/nonexistent/copilot-cli-binary',
            autoStart:  false,
        ));

        $this->expectException(ConnectionException::class);

        $client->start();
    }

    // =========================================================================
    // createSession() — autoStart=false, not connected → exception
    // =========================================================================

    public function testCreateSessionThrowsWhenNotConnectedAndAutoStartDisabled(): void
    {
        $client = new CopilotClient(new ClientOptions(autoStart: false));

        $this->expectException(ConnectionException::class);
        $client->createSession(new SessionConfig());
    }

    // =========================================================================
    // ModelInfo
    // =========================================================================

    public function testModelInfoFromArray(): void
    {
        $data = [
            'id'           => 'gpt-4.1',
            'name'         => 'GPT-4.1',
            'capabilities' => ['supports' => ['vision' => true]],
            'supportedReasoningEfforts' => ['low', 'medium', 'high'],
        ];

        $model = ModelInfo::fromArray($data);

        self::assertSame('gpt-4.1', $model->id);
        self::assertSame('GPT-4.1', $model->name);
        self::assertSame(['supports' => ['vision' => true]], $model->capabilities);
        self::assertSame(['low', 'medium', 'high'], $model->supportedReasoningEfforts);
    }

    public function testModelInfoFromArrayWithDefaults(): void
    {
        $model = ModelInfo::fromArray([]);

        self::assertSame('', $model->id);
        self::assertSame('', $model->name);
        self::assertSame([], $model->capabilities);
        self::assertSame([], $model->supportedReasoningEfforts);
    }

    // =========================================================================
    // SessionConfig
    // =========================================================================

    public function testSessionConfigDefaults(): void
    {
        $config = new SessionConfig();

        self::assertNull($config->sessionId);
        self::assertNull($config->model);
        self::assertSame([], $config->tools);
        self::assertNull($config->onPermissionRequest);
        self::assertNull($config->onEvent);
        self::assertNull($config->workingDirectory);
        self::assertNull($config->reasoningEffort);
        self::assertNull($config->systemMessage);
        self::assertNull($config->provider);
    }

    public function testSessionConfigWithValues(): void
    {
        $handler = fn ($req, $ctx) => ['kind' => 'approved'];
        $tool    = new Tool('my_tool', 'Does something');

        $config = new SessionConfig(
            sessionId:         'session-abc',
            model:             'claude-sonnet-4.5',
            tools:             [$tool],
            onPermissionRequest: $handler,
            workingDirectory:  '/workspace',
            reasoningEffort:   'high',
        );

        self::assertSame('session-abc', $config->sessionId);
        self::assertSame('claude-sonnet-4.5', $config->model);
        self::assertCount(1, $config->tools);
        self::assertSame($handler, $config->onPermissionRequest);
        self::assertSame('/workspace', $config->workingDirectory);
        self::assertSame('high', $config->reasoningEffort);
    }

    // =========================================================================
    // Tool
    // =========================================================================

    public function testToolDefaults(): void
    {
        $tool = new Tool('greet', 'Greet the user');

        self::assertSame('greet', $tool->name);
        self::assertSame('Greet the user', $tool->description);
        self::assertNull($tool->parameters);
        self::assertNull($tool->handler);
        self::assertFalse($tool->skipPermission);
    }

    public function testToolWithParameters(): void
    {
        $params = [
            'type'       => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required'   => ['name'],
        ];

        $tool = new Tool(
            name:        'greet',
            description: 'Greet a person',
            parameters:  $params,
            handler:     fn ($args) => "Hello {$args['name']}",
            skipPermission: true,
        );

        self::assertSame($params, $tool->parameters);
        self::assertTrue($tool->skipPermission);
        self::assertIsCallable($tool->handler);

        $result = ($tool->handler)(['name' => 'Alice'], []);
        self::assertSame('Hello Alice', $result);
    }
}
