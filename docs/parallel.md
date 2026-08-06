# 并行（多线程）与协程的结合

本库同时提供两种并发模型，并提供了把它们**桥接**在一起的门面：

- **协程（Fiber / kode/fibers）** —— 协作式并发，在**单个 OS 线程**内通过 `Fiber::suspend()` / `Fiber::resume()` 切换，适合 **I/O 密集型**任务（网络、文件、数据库）。
- **并行（Parallel / ext-parallel）** —— 抢占式**真正的多线程**，在**独立 OS 线程**上并行执行，适合 **CPU 密集型**任务（加解密、压缩、图像处理、大模型推理）。

二者互补：**协程负责 I/O 并发，并行负责 CPU 并发**。本库的 `Kode\Process\Parallel\Parallel` 门面把并行任务的「投送 / 等待」接入协程调度，让你在协程里以非阻塞方式等待一个真正跑在别的线程上的任务。

---

## 1. Fiber 与 Parallel 对比

| 维度 | Fiber（协程） | Parallel（并行） |
|------|---------------|------------------|
| 并发类型 | 协作式（cooperative） | 抢占式（preemptive） |
| 线程模型 | 单线程内切换 | 多个真实 OS 线程 |
| 适合场景 | I/O 密集型（网络/文件/DB） | CPU 密集型（计算/编码） |
| PHP 构建要求 | 任何 8.3+（含 NTS） | **必须 ZTS（线程安全）构建** |
| 所需扩展 | 无（PHP 内建 Fiber） | **ext-parallel**（或 kode/parallel 封装） |
| 失败传播 | 异常在协程内抛出 | 异常通过 `Future` 透传 |
| 本库入口 | `Kode::go()` / `Kode::batch()` | `Kode::parallel()` / `Kode::awaitParallel()` |

> ⚠️ **真正的多线程必须 ZTS 构建的 PHP + ext-parallel，二者缺一不可。** 普通的 NTS（Non-Thread-Safe）PHP 无法加载 `parallel` 扩展。下面的「环境探测」与「安装」章节会详细说明。

---

## 2. 环境探测

本库在运行前会自行探测环境，无需手动开关：

```php
use Kode\Process\Kode;
use Kode\Process\Parallel\Parallel;
use Kode\Process\Version;

Version::isZts();            // 当前 PHP 是否为 ZTS（线程安全）构建
Version::supportsParallel(); // ZTS + ext-parallel 同时满足时为 true
Parallel::backend();         // 返回 'ext-parallel' | 'kode-parallel' | 'none'
Parallel::isAvailable();     // 等价于 Version::supportsParallel()
Kode::supportsParallel();    // 同上，静态便捷入口
Kode::parallelBackend();     // 同上，返回后端名
```

完整信息可通过 `Kode::info()` / `Version::getInfo()` 查看，其中新增了 `zts`、`parallel`、`parallel_backend`、`pthreads` 四个字段。

```php
print_r(Kode::info());
// ...
// [zts] => false
// [parallel] => false
// [parallel_backend] => none
// [pthreads] => false
```

---

## 3. 安装真正的多线程支持

### 3.1 先确认当前 PHP 是否 ZTS

```bash
php -i | grep -i "thread safety"
# 输出 "Thread Safety => enabled" 才是 ZTS；"disabled" 则是 NTS
```

或在 PHP 中：`var_dump(PHP_ZTS === 1);`

### 3.2 Docker（推荐，最简单）

官方 PHP 镜像提供 `zts` 变体（如 `php:8.3-zts`、`php:8.3-zts-alpine`），已开启 Zend Thread Safety。

```dockerfile
FROM php:8.3-zts

# 安装 ext-parallel（仅能在 ZTS 构建上编译/加载）
RUN pecl install parallel \
    && docker-php-ext-enable parallel

# 安装本库与依赖
COPY . /var/www/app
WORKDIR /var/www/app
RUN composer install --no-dev --optimize-autoloader

CMD ["php", "server.php", "start"]
```

> `parallel` 扩展对 PHP 版本较敏感。若 `pecl install parallel` 安装了与你 PHP 不兼容的旧版本，可指定版本（例如 `pecl install parallel-1.2.3`）或测试版 `pecl install parallel-beta`，以 [PECL parallel 页面](https://pecl.php.net/package/parallel) 的兼容性说明为准。

### 3.3 Ubuntu / Debian 源码编译 ZTS PHP

系统仓库里的 `php-cli` 通常是 NTS，需要自行编译 ZTS 版本：

```bash
# 1) 安装编译依赖
sudo apt update
sudo apt install -y build-essential libxml2-dev pkg-config autoconf bison re2c \
    libsqlite3-dev libcurl4-openssl-dev libssl-dev libonig-dev

# 2) 下载并编译开启 --enable-zts 的 PHP
wget https://www.php.net/distributions/php-8.3.31.tar.xz
tar xf php-8.3.31.tar.xz
cd php-8.3.31
./buildconf --force
./configure --enable-zts --enable-cli \
    --with-pcntl --with-posix --with-sockets \
    --with-openssl --with-curl --enable-mbstring
make -j"$(nproc)"
sudo make install

# 3) 安装 ext-parallel
sudo pecl install parallel
echo "extension=parallel.so" | sudo tee /usr/local/lib/php.conf.d/parallel.ini
php -m | grep -i parallel   # 确认已加载
```

### 3.4 macOS（Homebrew 不提供 ZTS 版）

Homebrew 的 `php` 公式是 NTS 构建，无法加载 `parallel`。在 macOS 上有两种可行路径：

- **推荐：用 Docker**（见 3.2），最省心。
- 或**从源码编译 ZTS PHP**（步骤同 3.3，依赖用 `brew` 安装：`brew install autoconf bison re2c` 等）。

### 3.5 Windows

从 [windows.php.net](https://windows.php.net/download/) 下载 **TS（Thread Safe）** 版本，把对应的 `php_parallel.dll` 放入 `ext/` 目录并在 `php.ini` 中 `extension=parallel` 启用。

### 3.6 kode/parallel 封装包（可选）

`kode/parallel` 是对 `ext-parallel` 的一层封装，可一并安装，但只要底层是 ZTS + ext-parallel，本库的 `Parallel` 门面即可直接使用，无需额外依赖：

```bash
composer require kode/parallel   # 可选
```

---

## 4. 组合使用：在协程里等待并行任务

### 4.1 基本用法

```php
use Kode\Process\Kode;

Kode::go(function (): void {
    // 1) I/O 密集型：协程并发（kode/fibers 在单线程内调度）
    $html = file_get_contents('https://example.com/page');

    // 2) CPU 密集型：投到真正的并行线程
    $future = Kode::parallel(function (string $data): array {
        return expensiveCpuWork($data); // 在独立 OS 线程执行
    }, $html);

    // 3) 在协程内挂起等待：等待期间本协程让出控制权，
    //    事件循环可继续服务其它协程，不会阻塞整个进程
    $result = Kode::awaitParallel($future);

    echo "结果: " . json_encode($result) . "\n";
});
```

门面等价于直接调用 `Parallel` 类：

```php
use Kode\Process\Parallel\Parallel;

$future = Parallel::run($task, $arg1, $arg2); // 返回 FutureInterface
$value  = Parallel::await($future);           // 等待并取结果
```

### 4.2 await() 的两种语义

`Parallel::await()`（以及 `Kode::awaitParallel()`）会根据调用上下文自动选择行为：

| 调用上下文 | 行为 |
|-----------|------|
| **协程（Fiber）内** | 挂起当前协程，由所在事件循环（本库 `Async` 或 kode/fibers 的 `FiberPool`）在并行任务完成后**自动恢复**。等待期间不阻塞其它协程。 |
| **普通上下文（无 Fiber）** | 阻塞当前线程，直到任务完成（内部 `usleep` 轮询）。 |

`await()` 在协程内的桥接逻辑：

- 若本库 `Async` 事件循环正在驱动（`Async::run()` 中），注册一个 `Async::defer` 轮询器，任务完成后由事件循环 `resume` 协程；
- 否则（kode/fibers 的 `FiberPool` 或裸 Fiber）采用协作式忙轮询：`Fiber::suspend()` 让出控制权，由所在循环持续 `resume` 继续轮询，直到任务完成。

两种情况下，**真正的并行任务都在独立 OS 线程上执行**，`future->done()` 由该线程翻转，因此必须由所在循环持续轮询，而非等待「别人主动来恢复」。

### 4.3 阻塞等待（无协程）

```php
$future = Kode::parallel(function (int $n): int {
    return expensiveCompute($n);
}, 1_000_000);

$result = Kode::awaitParallel($future); // 阻塞直到完成
```

---

## 5. ⚠️ 重要澄清：kode/fibers 的 `parallel()` 不是真线程

`kode/fibers` 包里的 `Kode\Fibers\Fibers::parallel()` **只是协作式并发的别名**（底层等价于 `concurrent()` 在单线程内跑），**并不会创建真实 OS 线程**。同样，`RuntimeBridge::run()` 也只是同步调用 `$task()`，没有多线程能力。

如果你需要**真正的 CPU 多线程并行**，请务必使用本库的 `Parallel` 门面（`Kode::parallel()` / `Kode\Process\Parallel\Parallel`），它才会真正把任务投到 `parallel\Runtime`：

| 调用 | 是否真线程 |
|------|-----------|
| `Kode\Fibers\Fibers::parallel(...)` | ❌ 否（协作式，单线程） |
| `Kode\Process\Parallel\Parallel::run(...)` / `Kode::parallel(...)` | ✅ 是（需 ZTS + ext-parallel） |

---

## 6. 错误处理

当环境不支持真正的多线程（NTS，或未装 ext-parallel）时，`Kode::parallel()` / `Parallel::run()` 会抛出清晰的 `ParallelException`，并附带检测信息：

```php
use Kode\Process\Exceptions\ParallelException;

try {
    $future = Kode::parallel(fn() => 42);
} catch (ParallelException $e) {
    // 当前环境不支持真正的多线程并行（requires ZTS + ext-parallel）。
    //   检测: ZTS=no, ext-parallel=no, backend=none
    //   解决: 使用 ZTS 构建的 PHP 并安装 ext-parallel（或 kode/parallel 封装包）；详见 docs/parallel.md
    echo $e->getMessage();
}
```

并行任务**自身抛出的异常**不会在 `run()` 时抛出，而是在 `await()` 取结果时**原样透传**给调用方（在协程内通过 `Fiber::throw()` 抛出，阻塞上下文直接抛出）。

---

## 7. 限制与注意事项

1. **闭包不能捕获 `$this` / 引用外部变量**：`parallel\Runtime::run()` 的任务运行在别的线程，无法共享当前进程的变量状态。只能传**可序列化**的参数（`int`/`string`/`array` 等），并通过返回值回传结果。
2. **资源不能跨线程**：文件句柄、数据库连接、Socket 等资源无法在线程间传递，需在任务内部自行创建与释放。
3. **`pthreads` 已弃用**：`pthreads` 仅支持 PHP < 8，且已停止维护，请改用 `parallel`。本库已将其列为可选扩展但不再推荐使用。
4. **Future 只能取值一次**：`FutureInterface::value()` 解析后会关闭底层 `Runtime` 释放线程资源；重复调用返回首次结果（成功或失败均缓存）。
5. **并行度与 CPU 核心数匹配**：每个 `run()` 会占用一个 OS 线程，建议并发数控制在 CPU 核心数附近，避免线程过多导致上下文切换开销。

---

## 8. 相关文档

- [运行时兼容层（Runtime）](runtime.md) —— 一套 API 在 Swoole / Workerman 间可移植的架构与事件模型
- [安装指南](install.md) —— 环境要求与依赖
- [生产部署](deployment.md) —— 含「启用多线程并行（ZTS）」章节
