# Kode Process 项目规则

## 项目概述

`kode/process` 是一个高性能 PHP 进程与线程管理器，支持 Master-Worker 模型、进程池、IPC 通信、信号处理、队列消费，兼容 PHP 8.1-8.5+。

### 核心特性

- 🚀 极简 API - 一行代码启动服务器
- 🔄 Workerman 兼容 - 无缝迁移，零学习成本
- 📡 多协议支持 - HTTP、WebSocket、TCP、Text、UDP
- ⚡ Fiber 协程 - 集成 kode/fibers 包
- 📮 队列系统 - 集成 kode/queue 包
- 🌐 分布式集群 - Channel 通讯、GlobalData 共享

## 代码规范

### 命名规范

- **类名**: PascalCase（如 `ProcessManager`, `WorkerPool`）
- **接口**: PascalCase + Interface 后缀（如 `ProcessInterface`）
- **Trait**: PascalCase + Trait 后缀（如 `WorkerTrait`）
- **方法**: camelCase（如 `startWorker()`, `sendMessage()`）
- **常量**: UPPER_SNAKE_CASE（如 `STATE_RUNNING`, `SIGTERM`）
- **属性**: camelCase（如 `$workerPool`, `$processId`）

### 文件结构

```php
<?php

declare(strict_types=1);

namespace Kode\Process\Namespace;

use ClassName;

/**
 * 类文档注释（中文）
 */
final class ClassName
{
    public const CONSTANT = 'value';
    
    protected array $property;
    
    public function method(): void {}
}
```

### 类型声明

- 必须使用 `declare(strict_types=1);`
- 所有参数和返回值必须声明类型
- 使用 PHP 8.1+ 联合类型和可空类型
- 使用 `mixed` 类型处理不确定类型

### 注释规范

- 类、方法必须使用中文注释
- 复杂逻辑添加中文说明
- 避免无意义的注释

## 架构设计

### 核心组件

1. **Kode** - 静态入口类，提供极简 API
2. **Application** - 应用主类，管理生命周期
3. **Server** - 服务器类，处理网络通信
4. **Worker** - Worker 进程池，处理请求
5. **Protocol** - 协议系统，编解码数据
6. **Channel** - 分布式通讯组件
7. **Queue** - 队列系统，异步任务处理

### 目录结构

```
src/
├── Kode.php                 # 静态入口类
├── Application.php          # 应用主类
├── Server.php               # 服务器类
├── GlobalProcessManager.php # 全局进程管理器
├── Response.php             # 标准响应格式
├── Version.php              # 版本管理
├── Protocol/                # 协议系统
│   ├── ProtocolInterface.php
│   ├── ProtocolFactory.php
│   ├── HttpProtocol.php
│   ├── WebSocketProtocol.php
│   ├── TcpProtocol.php
│   ├── TextProtocol.php
│   ├── LengthPrefix.php
│   └── BinaryFile.php
├── Compat/                  # Workerman 兼容层
│   ├── Worker.php
│   ├── Timer.php
│   ├── Connection.php
│   └── StreamConnection.php
├── Server/                  # 服务器组件
│   ├── UdpServer.php
│   └── UdpClient.php
├── Channel/                 # 分布式通讯
│   ├── Server.php
│   └── Client.php
├── GlobalData/              # 全局数据共享
│   ├── Server.php
│   └── Client.php
├── Queue/                   # 队列系统
│   ├── QueueManager.php
│   ├── QueueWorker.php
│   ├── Job.php
│   └── Adapters/
├── Task/                    # 异步任务
│   ├── AsyncTask.php
│   └── TaskClient.php
├── Broadcast/               # 广播系统
├── Ssl/                     # SSL/TLS
├── Auth/                    # 连接认证
├── Reload/                  # 平滑重载
├── Crontab/                 # 定时任务
├── Monitor/                 # 监控组件
├── Debug/                   # 调试工具
├── Async/                   # 异步工具
├── Cluster/                 # 分布式集群
├── Integration/             # 框架集成
├── Signal/                  # 信号处理
├── IPC/                     # 进程间通信
├── Contracts/               # 契约接口
├── Exceptions/              # 异常类
└── Benchmark/               # 性能测试
```

## 依赖关系

```
kode/process
├── 必需依赖
│   ├── kode/fibers    → 协程调度、Channel、熔断器
│   ├── kode/queue     → 队列消费
│   ├── kode/context   → 上下文管理
│   └── psr/log        → 日志接口
└── 可选依赖
    ├── kode/parallel  → 多线程并行（需要 ext-parallel）
    └── kode/runtime   → 运行时环境适配
```

## 协议系统

### 协议接口

所有协议必须实现 `ProtocolInterface`，使用静态方法：

```php
interface ProtocolInterface
{
    public static function getName(): string;
    public static function input(string $buffer, mixed $connection = null): int;
    public static function encode(mixed $data, mixed $connection = null): string;
    public static function decode(string $buffer, mixed $connection = null): mixed;
}
```

### 内置协议

| 协议 | 说明 | 地址格式 |
|------|------|----------|
| http | HTTP 协议 | `http://0.0.0.0:8080` |
| websocket | WebSocket 协议 | `websocket://0.0.0.0:8081` |
| tcp | TCP 原始协议 | `tcp://0.0.0.0:9000` |
| text | 文本+换行符 | `text://0.0.0.0:9001` |
| udp | UDP 协议 | `udp://0.0.0.0:9002` |
| ssl | SSL/TLS | `ssl://0.0.0.0:443` |

## 测试规范

- 使用 PHPUnit 10+
- 测试文件命名：`*Test.php`
- 覆盖率要求：核心功能 > 80%
- 中文注释说明测试目的

## 命令

```bash
# 运行测试
composer test
./vendor/bin/phpunit

# 代码检查
composer lint
./vendor/bin/phpcs

# 类型检查
composer typecheck
./vendor/bin/phpstan

# 压测
php examples/18-benchmark-compare.php
```

## 示例文件

| 文件 | 说明 |
|------|------|
| 01-simple-worker.php | 简单 Worker 示例 |
| 02-signal-handling.php | 信号处理 |
| 03-ipc-communication.php | IPC 通信 |
| 07-http-server.php | HTTP 服务器 |
| 16-workerman-compat.php | Workerman 兼容 |
| 18-benchmark-compare.php | 性能压测 |
| 28-broadcast-chat.php | 广播聊天 |
| 32-websocket-chat.php | WebSocket 聊天 |

## 版本规范

- 遵循语义化版本号：MAJOR.MINOR.PATCH
- 当前版本：2.4.1
- PHP 最低版本：8.1
