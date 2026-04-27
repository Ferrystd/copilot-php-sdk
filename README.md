# GitHub Copilot PHP SDK

GitHub Copilot PHP SDK（社区实现），用于通过 JSON-RPC 以编程方式控制 Copilot CLI。

> **注意**：当前处于 Public Preview 阶段，接口可能发生不兼容变更。

---

## 1. 能力概览

- 支持 **V1（当前）** 与 **V0（兼容）** 两套命名空间
- 通过 Copilot CLI server mode 与 GitHub Copilot 服务通信
- 支持会话创建、恢复、消息收发、模型切换、配额查询
- 支持自定义 Tool、Command、权限审批、用户输入、Elicitation
- 支持 MCP 配置与发现、技能发现与禁用、Session Fork（V1）

命名空间状态：

| Namespace | 状态 |
|---|---|
| `Github\\Copilot\\V1` | 当前推荐 |
| `Github\\Copilot\\V0` | 兼容（历史版本） |

---

## 2. 环境要求

- PHP 8.1+
- 已安装并可用的 Copilot CLI（`copilot`）
- 已完成 CLI 认证（或使用 BYOK）

---

## 3. 安装

```bash
composer require github/copilot-php-sdk
```

---

## 4. 架构（全链路）

```text
你的 PHP 应用
    ↓
CopilotClient / CopilotSession
    ↓ JSON-RPC 2.0（stdio / TCP）
Copilot CLI（server mode）
    ↓
GitHub Copilot API
```

---

## 5. 快速开始（V1）

```php
<?php

require 'vendor/autoload.php';

use Github\Copilot\V1\CopilotClient;
use Github\Copilot\V1\CopilotSession;
use Github\Copilot\V1\Types\MessageOptions;
use Github\Copilot\V1\Types\SessionConfig;

$client = new CopilotClient();
$session = $client->createSession(new SessionConfig(
    model: 'gpt-4.1',
    onPermissionRequest: CopilotSession::approveAll(),
));

$session->setMode('interactive');
$response = $session->sendAndWait(new MessageOptions('请总结当前仓库。'));

echo $response?->getAssistantContent() . PHP_EOL;

$session->disconnect();
$client->stop();
```

---

## 6. 全链路打通示例（V1）

仓库已提供完整示例：

- `samples/full_chain_v1.php`

覆盖能力：

1. Client 启动 + Session 创建
2. Tool 回调
3. Command 回调
4. Permission 处理
5. `user_input.requested` 处理
6. `elicitation.requested` 处理
7. 流式输出 + `sendAndWait`
8. 资源清理（`disconnect` / `stop`）

运行：

```bash
php samples/full_chain_v1.php
```

---

## 7. 常用 API（V1）

### CopilotClient

- `start(): void`
- `stop(): array`
- `forceStop(): void`
- `createSession(SessionConfig): CopilotSession`
- `resumeSession(string, ResumeSessionConfig): CopilotSession`
- `ping(string): array`
- `listModels(): ModelInfo[]`
- `listTools(?string $model): array`
- `getQuota(): array`
- `mcpConfigList(): array`
- `mcpConfigAdd(McpServerConfig): void`
- `mcpConfigUpdate(McpServerConfig): void`
- `mcpConfigRemove(string): void`
- `mcpDiscover(?string): array`
- `discoverSkills(array, array): array`
- `setDisabledSkills(array): void`
- `forkSession(string, ?string): array`

### CopilotSession

- 消息：`send()` / `sendAndWait()` / `getMessages()`
- 模型：`getCurrentModel()` / `switchModel()`
- 模式：`getMode()` / `setMode()`
- 会话管理：`getName()` / `setName()` / `disconnect()`
- Workspace：`getWorkspace()` / `listWorkspaceFiles()` / `readWorkspaceFile()` / `createWorkspaceFile()`
- 历史：`compactHistory()` / `truncateHistory()`
- Shell：`execShell()` / `killShell()`
- Agent / Skills / MCP / Extensions / Plugins 对应 `list|enable|disable|reload` 方法

---

## 8. V0 示例

- `php samples/chat.php`：V0 基础对话
- `php samples/custom_tool.php`：V0 自定义工具
- `php samples/chat_v1.php`：V1 基础对话

---

## 9. 测试

```bash
composer install
./vendor/bin/phpunit
```

---

## 10. 许可证

MIT
