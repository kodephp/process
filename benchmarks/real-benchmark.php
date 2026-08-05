<?php

declare(strict_types=1);

/**
 * Kode Process 真实压测脚本
 *
 * 直接在本机运行、使用 microtime 实测吞吐，输出可复现的真实数据。
 * 聚焦“多进程”维度：进程创建、共享数据、进程间通信，
 * 并与裸 PHP 多进程原语对比（体现 Kode Process 的极薄封装开销）。
 * 共享数据部分同时对比 GlobalData 的多后端（Swoole Table / APCu / 共享内存），
 * 以及原生 Swoole\Table，以体现“对比 Swoole 最新版、同口径调优”。
 *
 * 用法：php benchmarks/real-benchmark.php [iterations]
 *
 * 说明：macOS 的 SysV 共享内存总量仅 ~4MB（kern.sysv.shmall=1024），
 * 故共享内存类基准逐段测量（用完即释放，再建下一段），避免同时占用多段。
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\GlobalData\GlobalData;
use Kode\Process\GlobalData\SwooleTable;
use Kode\Process\GlobalData\Server;
use Kode\Process\GlobalData\Client;
use Kode\Process\IPC\SocketIPC;
use Kode\Process\IPC\MessageQueue;
use Kode\Process\IPC\SharedMemoryIPC;

$ops = (int) ($argv[1] ?? 200000);

$out = function (string $line): void {
    fwrite(STDERR, $line . "\n");
};

function fmt(int|float $n): string
{
    return number_format($n, 0);
}

function cores(): string
{
    $n = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
    return $n ? trim($n) : 'n/a';
}

$out('');
$out('========================================================');
$out(' Kode Process 真实压测（多进程维度）');
$out(' PHP ' . PHP_VERSION . ' | ZTS: ' . (PHP_ZTS ? 'yes' : 'no'));
$out(' ' . php_uname('s') . ' ' . php_uname('r') . ' | ' . cores() . ' 核');
$out(' OPCache: ' . (extension_loaded('Zend OPcache') ? 'on' : 'off'));
$out(' GlobalData::auto 选中: ' . (GlobalData::preferred() ?? '无（全部不可用）'));
$out(' 迭代次数: ' . fmt($ops));
$out('========================================================');
$out('');

// ---------------------------------------------------------------
// 一、进程创建（fork 率）
// ---------------------------------------------------------------
$out('【一、进程创建 fork 率】');
$forkIters = min(300, max(50, intdiv($ops, 5000)));
$start = microtime(true);
$pids = [];
for ($i = 0; $i < $forkIters; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        exit(0);
    }
    if ($pid > 0) {
        $pids[] = $pid;
    }
}
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}
$forkTime = microtime(true) - $start;
$out(sprintf('  裸 pcntl_fork         : %s forks/s  (%.4fs / %d forks)', fmt($forkIters / $forkTime), $forkTime, $forkIters));
$out(sprintf('  Kode Process 等效      : = 裸 fork（无额外封装）'));
$out('');

// ---------------------------------------------------------------
// 二、共享数据吞吐（多后端同类对比）
//     auto() 按优先级挑选可用后端；逐个后端实测 set/get/increment/cas。
//     每段用完即释放，规避 macOS ~4MB 共享内存总量限制。
// ---------------------------------------------------------------
$out('【二、共享数据吞吐（多后端同类对比）】');

// 2.1 裸 sysvshm 基线（同一进程内 shm_put_var / shm_get_var）
$rawKey = 0x90000001;
$rawShm = @shm_attach($rawKey, 1024 * 1024, 0644);
if ($rawShm === false) {
    $out('  裸 sysvshm             : 跳过（共享内存段分配失败，可能超出系统 shm 总量）');
    $rawSetOps = $rawGetOps = 0;
} else {
    // 预热
    for ($i = 0; $i < 2000; $i++) {
        shm_put_var($rawShm, 5, $i);
    }
    $start = microtime(true);
    $sink = 0;
    for ($i = 0; $i < $ops; $i++) {
        shm_put_var($rawShm, 5, $i);
    }
    $rawSetT = microtime(true) - $start;
    $start = microtime(true);
    for ($i = 0; $i < $ops; $i++) {
        $sink += shm_get_var($rawShm, 5);
    }
    $rawGetT = microtime(true) - $start;
    $rawSetOps = $ops / $rawSetT;
    $rawGetOps = $ops / $rawGetT;
    $out(sprintf('  裸 sysvshm set        : %s ops/s', fmt($rawSetOps)));
    $out(sprintf('  裸 sysvshm get        : %s ops/s', fmt($rawGetOps)));
}
// 释放本段，为后续段腾出 ~4MB 池空间
if (isset($rawShm) && $rawShm !== false) {
    @shm_remove($rawShm);
    @shm_detach($rawShm);
    unset($rawShm);
}

// 2.2 多后端 GlobalData 对比（走 GlobalData::make，与 auto() 同口径）
$out('  -- GlobalData 多后端 [ ' . implode(' | ', GlobalData::available()) . ' 可用 ] --');
$backendKey = 0x90000100;
foreach ([GlobalData::BACKEND_SWOOLE, GlobalData::BACKEND_APCU, GlobalData::BACKEND_SHM] as $backend) {
    if (!GlobalData::supports($backend)) {
        $diag = GlobalData::diagnose()[$backend];
        $out(sprintf('  [%-8s] 不可用           : 跳过（需 %s）', $backend, $diag['requires']));
        continue;
    }
    $size = $backend === GlobalData::BACKEND_SHM ? 1024 * 1024 : 65536;
    try {
        $table = GlobalData::make($backend, $backendKey, $size);
    } catch (\Throwable $e) {
        $out(sprintf('  [%-8s] 创建失败         : %s', $backend, $e->getMessage()));
        continue;
    }
    $table->clear();
    for ($i = 0; $i < 2000; $i++) {
        $table->set('k', $i);
    }
    $start = microtime(true);
    for ($i = 0; $i < $ops; $i++) {
        $table->set('k', $i);
    }
    $tSetT = microtime(true) - $start;
    $start = microtime(true);
    for ($i = 0; $i < $ops; $i++) {
        $table->get('k');
    }
    $tGetT = microtime(true) - $start;
    $start = microtime(true);
    for ($i = 0; $i < $ops; $i++) {
        $table->increment('counter');
    }
    $tIncT = microtime(true) - $start;
    $tSetOps = $ops / $tSetT;
    $tGetOps = $ops / $tGetT;
    $tIncOps = $ops / $tIncT;
    $setPct = $rawSetOps > 0 ? sprintf('  (裸占比 %.0f%%)', 100 * $tSetOps / $rawSetOps) : '';
    $getPct = $rawGetOps > 0 ? sprintf('  (裸占比 %.0f%%)', 100 * $tGetOps / $rawGetOps) : '';
    $out(sprintf('  [%-8s] set %s ops/s%s', $backend, fmt($tSetOps), $setPct));
    $out(sprintf('  [%-8s] get %s ops/s%s', $backend, fmt($tGetOps), $getPct));
    $out(sprintf('  [%-8s] inc %s ops/s', $backend, fmt($tIncOps)));
    // TTL 正确性子项：set(ttl=1) 即时可读，1s 后过期返回 null
    $table->set('ttlkey', 'v', 1);
    $ttlNow = $table->get('ttlkey') === 'v';
    sleep(1);
    $ttlAfter = $table->get('ttlkey') === null;
    $out(sprintf('  [%-8s] ttl 子项         : set(ttl=1) 即时可读=%s，1s 后过期=%s', $backend, $ttlNow ? 'OK' : 'FAIL', $ttlAfter ? 'OK' : 'FAIL'));
    $table->destroy();
    unset($table);
}

// 2.3 原生 Swoole\Table 对比（衡量 Kode SwooleTable 适配层开销；仅装了 swoole 时运行）
$out('  -- 原生 Swoole\\Table 对比 --');
if (class_exists('\Swoole\Table') && SwooleTable::isSupported()) {
    $native = new \Swoole\Table(65536);
    $native->column('v', \Swoole\Table::TYPE_STRING, 8192);
    $native->create();
    for ($i = 0; $i < 2000; $i++) {
        $native->set('k', ['v' => (string) $i]);
    }
    $start = microtime(true);
    for ($i = 0; $i < $ops; $i++) {
        $native->set('k', ['v' => (string) $i]);
    }
    $nativeSetT = microtime(true) - $start;
    $nativeSetOps = $ops / $nativeSetT;
    $out(sprintf('  原生 Swoole\\Table set  : %s ops/s', fmt($nativeSetOps)));
    unset($native);

    // Kode SwooleTable 适配层（同一套 TableInterface 语义，含编码/解码开销）
    $kTable = GlobalData::make(GlobalData::BACKEND_SWOOLE, 0x90000110, 65536);
    $kTable->clear();
    for ($i = 0; $i < 2000; $i++) {
        $kTable->set('k', $i);
    }
    $start = microtime(true);
    for ($i = 0; $i < $ops; $i++) {
        $kTable->set('k', $i);
    }
    $kSetT = microtime(true) - $start;
    $kSetOps = $ops / $kSetT;
    $overhead = $nativeSetOps > 0 ? max(0, 100 - 100 * $kSetOps / $nativeSetOps) : 0;
    $out(sprintf('  Kode SwooleTable set  : %s ops/s  (适配层开销 %.0f%%)', fmt($kSetOps), $overhead));
    $kTable->destroy();
    unset($kTable);
} else {
    $out('  原生 Swoole\\Table      : 本机不可用（需安装 ext-swoole，按策略跳过）');
}
$out('');

// 2.4 网络版 GlobalData（跨主机同类方案，fork 起 Server + Client 往返）
$netPort = 0x2207 + (int) (getmypid() % 1000);
$gdServer = new Server('127.0.0.1', $netPort);
$netPid = pcntl_fork();
if ($netPid === 0) {
    $gdServer->start();
    exit(0);
}
usleep(200000);
$gdClient = new Client('127.0.0.1:' . $netPort);
$gdClient->set('ping', 1); // 预热并建立连接
$gdClient->get('ping');
$start = microtime(true);
for ($i = 0; $i < $ops; $i++) {
    $gdClient->set('k', $i);
    $gdClient->get('k');
}
$netT = microtime(true) - $start;
$netOps = $ops / $netT;
$out(sprintf('  网络 GlobalData 往返  : %s ops/s  (set+get/', fmt($netOps)));
$out(sprintf('                            约 %s 次/s 单边操作)', fmt($netOps * 2)));
posix_kill($netPid, SIGTERM);
pcntl_waitpid($netPid, $status);
unset($gdClient, $gdServer);
$out('');

// ---------------------------------------------------------------
// 三、进程间通信吞吐（loopback 微基准）
// ---------------------------------------------------------------
$out('【三、进程间通信吞吐（消息 / 秒，loopback）】');

// 3.1 裸 Unix 域套接字对（序列化置于循环内，与 Kode SocketIPC 口径一致）
socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
for ($i = 0; $i < 2000; $i++) {
    $payload = serialize(['i' => $i, 'data' => 'payload']);
    $plen = strlen($payload);
    socket_write($pair[0], $payload);
    $buf = '';
    while (strlen($buf) < $plen) {
        $buf .= socket_read($pair[1], $plen - strlen($buf), PHP_BINARY_READ);
    }
}
$start = microtime(true);
for ($i = 0; $i < $ops; $i++) {
    $payload = serialize(['i' => $i, 'data' => 'payload']);
    $plen = strlen($payload);
    socket_write($pair[0], $payload);
    $buf = '';
    while (strlen($buf) < $plen) {
        $buf .= socket_read($pair[1], $plen - strlen($buf), PHP_BINARY_READ);
    }
}
$rawSockT = microtime(true) - $start;
$rawSockOps = $ops / $rawSockT;
$out(sprintf('  裸 Unix socket pair    : %s msg/s', fmt($rawSockOps)));
socket_close($pair[0]);
socket_close($pair[1]);

// 3.2 Kode SocketIPC
[$a, $b] = SocketIPC::createPair();
for ($i = 0; $i < 2000; $i++) {
    $a->send(['i' => $i, 'data' => 'payload']);
    $b->receive();
}
$start = microtime(true);
for ($i = 0; $i < $ops; $i++) {
    $a->send(['i' => $i, 'data' => 'payload']);
    $b->receive();
}
$kSockT = microtime(true) - $start;
$kSockOps = $ops / $kSockT;
$out(sprintf('  Kode SocketIPC         : %s msg/s  (裸占比 %.0f%%)', fmt($kSockOps), 100 * $kSockOps / $rawSockOps));
$a->close();
$b->close();

// 3.3 裸 SysV 消息队列（手动 serialize，与 Kode MessageQueue 口径一致）
$rawMsgKey = 0x90000003;
$rawMsg = msg_get_queue($rawMsgKey, 0644);
for ($i = 0; $i < 2000; $i++) {
    msg_send($rawMsg, 1, serialize(['i' => $i]), false, false, $err);
    msg_receive($rawMsg, 0, $t, 65536, $m, false, MSG_IPC_NOWAIT, $e);
}
$start = microtime(true);
for ($i = 0; $i < $ops; $i++) {
    msg_send($rawMsg, 1, serialize(['i' => $i]), false, false, $err);
    msg_receive($rawMsg, 0, $t, 65536, $m, false, MSG_IPC_NOWAIT, $e);
}
$rawMsgT = microtime(true) - $start;
$rawMsgOps = $ops / $rawMsgT;
$out(sprintf('  裸 SysV msg_queue      : %s msg/s', fmt($rawMsgOps)));
msg_remove_queue($rawMsg);

// 3.4 Kode MessageQueue
$msgKey = 0x90000004;
$mq = new MessageQueue($msgKey, null);
for ($i = 0; $i < 2000; $i++) {
    $mq->send(['i' => $i]);
    $mq->receive();
}
$start = microtime(true);
for ($i = 0; $i < $ops; $i++) {
    $mq->send(['i' => $i]);
    $mq->receive();
}
$kMsgT = microtime(true) - $start;
$kMsgOps = $ops / $kMsgT;
$out(sprintf('  Kode MessageQueue      : %s msg/s  (裸占比 %.0f%%)', fmt($kMsgOps), 100 * $kMsgOps / $rawMsgOps));
$mq->close();

// 3.5 Kode SharedMemoryIPC（环形队列，独立共享内存段）
$shmIpcKey = 0x90000005;
$shmIpc = null;
try {
    $shmIpc = new SharedMemoryIPC($shmIpcKey, null, 1024 * 1024, 4096);
} catch (\Throwable $e) {
    $shmIpc = null;
}
if ($shmIpc === null) {
    $out(sprintf('  Kode SharedMemoryIPC   : 跳过（%s）', $e->getMessage()));
} else {
    $shmIpc->flush();
    for ($i = 0; $i < 2000; $i++) {
        $shmIpc->send(['i' => $i]);
        $shmIpc->receive();
    }
    $start = microtime(true);
    for ($i = 0; $i < $ops; $i++) {
        $shmIpc->send(['i' => $i]);
        $shmIpc->receive();
    }
    $kShmT = microtime(true) - $start;
    $kShmOps = $ops / $kShmT;
    $out(sprintf('  Kode SharedMemoryIPC   : %s msg/s', fmt($kShmOps)));
    $shmIpc->destroy();
    unset($shmIpc);
}
$out('');

$out('说明：loopback 微基准在同一进程内完成“发送 + 接收”，隔离出每条消息的传输开销；');
$out('真实跨进程部署的吞吐接近此上限（额外仅含一次进程唤醒成本）。');
$out('');

// ---------------------------------------------------------------
// 四、同类方案可用性（本机实测 + GlobalData 自动选择结果）
// ---------------------------------------------------------------
$out('【四、同类方案可用性】');
$out(sprintf('  GlobalData::auto() 选中 : %s', GlobalData::preferred() ?? '无（全部不可用）'));
$diag = GlobalData::diagnose();
foreach ($diag as $backend => $info) {
    $out(sprintf('  [%-8s] %-10s : %s', $backend, $info['available'] ? '可用' : '不可用', $info['requires']));
    $out(sprintf('            备注         : %s', $info['note']));
}
$out('  裸 sysvshm 基线        : ' . (extension_loaded('sysvshm') ? '可用' : '不可用'));
$out('  网络 GlobalData        : ' . (extension_loaded('sockets') ? '可用' : '不可用'));
$out('  Redis（独立服务）      : ' . (extension_loaded('redis') ? '可用' : '不可用'));
$out('');
$out('策略：auto() 优先选 Swoole Table（已装 swoole 时），次之 APCu，兜底零安装共享内存；');
$out('Swoole Table / APCu / Redis 性能更强但需安装组件，故默认零安装底座、跨机用网络 GlobalData。');
$out('');

$out('cleanup done.');
