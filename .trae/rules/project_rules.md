# Kode Process 项目规则

## 项目概述
`kode/process` 是一个高性能 PHP 进程与线程管理器，支持 Master-Worker 模型、进程池、IPC 通信和信号处理。

## 代码规范

### 命名规范
- 类名：PascalCase（如 `ProcessManager`, `WorkerPool`）
- 接口：PascalCase + Interface 后缀（如 `ProcessInterface`, `WorkerInterface`）
- 方法：camelCase（如 `startWorker()`, `sendMessage()`）
- 常量：UPPER_SNAKE_CASE（如 `STATE_RUNNING`, `SIGTERM`）
- 属性：camelCase（如 `$workerPool`, `$processId`）

### 文件结构
```php
<?php

declare(strict_types=1);

namespace Kode\Process\Namespace;

use ClassName;

/**
 * 类文档注释
 */
class ClassName
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

## 架构设计

### 核心组件
1. **Master 进程** - 管理端口监听、信号处理、日志轮转
2. **Worker 进程池** - 预启动进程，运行 Event Loop 和 Fiber
3. **IPC 通信** - Socket Pair / 共享内存消息传递
4. **信号处理器** - SIGTERM, SIGINT, SIGUSR1, SIGUSR2
5. **进程监控** - 心跳检测、自动重启、资源限制

### 目录结构
```
src/
├── Contracts/          # 契约接口
│   ├── ProcessInterface.php
│   ├── WorkerInterface.php
│   ├── IPCInterface.php
│   └── SignalHandlerInterface.php
├── Master/             # Master 进程管理
│   ├── MasterProcess.php
│   └── ProcessManager.php
├── Worker/             # Worker 进程池
│   ├── WorkerPool.php
│   ├── WorkerProcess.php
│   └── WorkerFactory.php
├── IPC/                # 进程间通信
│   ├── SocketIPC.php
│   ├── SharedMemoryIPC.php
│   └── MessageQueue.php
├── Signal/             # 信号处理
│   ├── SignalHandler.php
│   └── SignalDispatcher.php
├── Monitor/            # 进程监控
│   ├── ProcessMonitor.php
│   └── Heartbeat.php
├── Thread/             # 线程支持（可选）
│   ├── ThreadPool.php
│   └── Thread.php
├── Exceptions/         # 异常类
│   └── ProcessException.php
├── Process.php         # 门面类
└── functions.php       # 辅助函数
```

## 测试规范
- 使用 PHPUnit 10+
- 测试文件命名：`*Test.php`
- 覆盖率要求：核心功能 > 80%

## 命令
- 运行测试：`composer test` 或 `./vendor/bin/phpunit`
- 代码检查：`composer lint` 或 `./vendor/bin/phpcs`
- 类型检查：`composer typecheck` 或 `./vendor/bin/phpstan`
