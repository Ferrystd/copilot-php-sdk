<?php

/**
 * GitHub Copilot PHP SDK — V1 全链路示例
 *
 * 覆盖链路：
 * 1) Client 启动与会话创建
 * 2) 自定义 Tool / Command
 * 3) 权限审批
 * 4) 用户输入（user_input.requested）
 * 5) 表单交互（elicitation.requested）
 * 6) 流式输出与 sendAndWait
 * 7) 会话与客户端关闭
 *
 * 运行：php samples/full_chain_v1.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Github\Copilot\V1\CopilotClient;
use Github\Copilot\V1\CopilotSession;
use Github\Copilot\V1\Events\SessionEvent;
use Github\Copilot\V1\Types\Command;
use Github\Copilot\V1\Types\MessageOptions;
use Github\Copilot\V1\Types\SessionConfig;
use Github\Copilot\V1\Types\Tool;

$tool = new Tool(
    name: 'get_weather',
    description: '获取城市天气（示例模拟数据）',
    parameters: [
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'description' => '城市名'],
        ],
        'required' => ['city'],
    ],
    handler: static function (array $args): string {
        $city = (string)($args['city'] ?? 'unknown');
        return json_encode(['city' => $city, 'weather' => 'sunny', 'tempC' => 22], JSON_UNESCAPED_UNICODE);
    },
    skipPermission: true,
);

$command = new Command(
    name: 'hello',
    description: '演示自定义斜杠命令',
    handler: static function (string $commandText, string $args): void {
        fwrite(STDOUT, "\n[command] {$commandText} {$args}\n");
    }
);

$client = new CopilotClient();
$session = $client->createSession(new SessionConfig(
    model: 'gpt-4.1',
    tools: [$tool],
    commands: [$command],
    onPermissionRequest: CopilotSession::approveAll(),
    onUserInputRequest: static function (array $request): string {
        $prompt = (string)($request['prompt'] ?? '请输入内容：');
        fwrite(STDOUT, "\n[user_input.requested] {$prompt}\n> ");
        $line = fgets(STDIN);
        return trim((string)$line);
    },
    onElicitationRequest: static function (array $request): array {
        $message = (string)($request['message'] ?? '请确认');
        fwrite(STDOUT, "\n[elicitation.requested] {$message} (y/n): ");
        $line = strtolower(trim((string)fgets(STDIN)));
        if (in_array($line, ['y', 'yes'], true)) {
            return ['action' => 'accept', 'content' => ['confirmed' => true]];
        }
        return ['action' => 'decline'];
    },
));

$session->setMode('interactive');

$session->onType(SessionEvent::TYPE_ASSISTANT_MESSAGE_DELTA, static function (SessionEvent $event): void {
    echo $event->getDelta() ?? '';
    flush();
});

echo "Copilot PHP SDK V1 全链路演示（输入 exit 退出）\n";
echo str_repeat('─', 60) . "\n\n";

while (true) {
    echo 'You: ';
    $line = fgets(STDIN);
    if ($line === false) {
        break;
    }

    $line = trim($line);
    if ($line === '') {
        continue;
    }

    if (in_array($line, ['exit', 'quit', 'q'], true)) {
        break;
    }

    echo 'Copilot: ';

    try {
        $response = $session->sendAndWait(new MessageOptions($line), timeout: 180.0);
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
