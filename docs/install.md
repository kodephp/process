# 安装指南

## 环境要求

- **PHP**: >= 8.1
- **扩展**: pcntl, posix, sockets
- **可选扩展**: sysvmsg, sysvshm, sysvsem, pthreads, parallel, swoole

## 安装方式

### Composer 安装

```bash
composer require kode/process
```

### 手动安装

在 `composer.json` 中添加：

```json
{
    "require": {
        "kode/process": "^2.4"
    }
}
```

然后运行：

```bash
composer install
```

## 依赖说明

### 必需依赖

| 包 | 说明 |
|---|---|
| kode/fibers | 协程调度、Channel、熔断器 |
| kode/queue | 队列消费 |
| kode/context | 上下文管理 |
| psr/log | 日志接口 |

### 可选依赖

| 包 | 说明 |
|---|---|
| kode/parallel | 多线程并行（需要 ext-parallel） |
| kode/runtime | 运行时环境适配 |

## 验证安装

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Kode\Process\Kode;

echo "Kode Process 版本: " . Kode::version() . "\n";
```

## 常见问题

### 1. pcntl 扩展未安装

```bash
# Ubuntu/Debian
sudo apt-get install php-pcntl

# CentOS/RHEL
sudo yum install php-pcntl

# macOS (Homebrew)
brew install php
```

### 2. posix 扩展未安装

```bash
# Ubuntu/Debian
sudo apt-get install php-posix

# CentOS/RHEL
sudo yum install php-process
```

### 3. 权限问题

确保有权限绑定端口（1024 以下端口需要 root 权限）：

```bash
# 使用 sudo 运行
sudo php your-server.php start

# 或使用高于 1024 的端口
php your-server.php start  # 使用 8080 等端口
```
