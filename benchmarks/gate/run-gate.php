<?php

declare(strict_types=1);

/**
 * 五维硬门槛 Gate 判定器
 *
 * 判定「自研网络 I/O 内核」相对 Workerman 基线是否值得保留：
 *   G1 吞吐   QPS(候选) >= QPS(Workerman) * 1.30
 *   G2 延迟   P99(候选) <= P99(Workerman)
 *   G3 稳定   3 轮中位数，相对标准差 RSD < 5%
 *   G4 正确   0 socket error / 0 非 2xx
 *   G5 内存   RSS(候选) <= RSS(Workerman) * 1.5
 * 五项全过 → 保留自研内核；任一不过 → 降级为 Swoole/Workerman 兼容层。
 *
 * 用法： php run-gate.php [轮数] [压测秒数]
 */

const IMPLS = [
    'workerman' => ['script' => 'wm-http.php',      'args' => 'start', 'pattern' => 'WorkerMan',        'role' => 'baseline'],
    'kode-bev'  => ['script' => 'spike-bev.php',    'args' => '',      'pattern' => 'spike-bev.php',    'role' => 'candidate'],
    'kode-evh'  => ['script' => 'spike-evhttp.php', 'args' => '',      'pattern' => 'spike-evhttp.php', 'role' => 'candidate'],
    'swoole'    => ['script' => 'sw-http.php',      'args' => '',      'pattern' => 'sw-http.php',      'role' => 'reference'],
];

const WORKERS   = 4;
const THREADS   = 6;
const CONNS     = 200;
const GATE_QPS  = 1.30; // 吞吐门槛倍数
const GATE_RSD  = 5.0;  // 稳定性门槛（%）
const GATE_MEM  = 1.50; // 内存门槛倍数

$rounds = (int)($argv[1] ?? 3);
$dur    = (int)($argv[2] ?? 10);
$dir    = __DIR__;
$port   = 9300;

function shell(string $cmd): string
{
    return (string)shell_exec($cmd . ' 2>&1');
}

function stopAll(): void
{
    foreach (IMPLS as $m) {
        shell('pkill -9 -f ' . escapeshellarg($m['pattern']));
    }
    usleep(800_000);
}

function startServer(array $meta, int $port, string $dir): bool
{
    // 必须用子 shell 完整分离：否则后台进程继承 shell_exec 的 stdout 管道，
    // 管道永不 EOF，shell_exec 将永久阻塞。
    $cmd = sprintf(
        '( cd %s && BENCH_W=%d BENCH_PORT=%d nohup php %s %s >/dev/null 2>&1 </dev/null & ) >/dev/null 2>&1',
        escapeshellarg($dir), WORKERS, $port, escapeshellarg($meta['script']), $meta['args']
    );
    shell($cmd);
    for ($i = 0; $i < 40; $i++) {
        $code = trim(shell(sprintf(
            'curl -s -o /dev/null -w "%%{http_code}" --max-time 1 http://127.0.0.1:%d/', $port
        )));
        if ($code === '200') {
            return true;
        }
        usleep(250_000);
    }
    return false;
}

/** 累加所有匹配进程的 RSS（KB） */
function rssOf(string $pattern): int
{
    $pids = array_filter(array_map('trim', explode("\n", shell('pgrep -f ' . escapeshellarg($pattern)))));
    $sum  = 0;
    foreach ($pids as $pid) {
        $sum += (int)trim(shell(sprintf('ps -o rss= -p %d', (int)$pid)));
    }
    return $sum;
}

/** 累加所有匹配进程的 CPU 时间（秒） */
function cpuOf(string $pattern): float
{
    $pids  = array_filter(array_map('trim', explode("\n", shell('pgrep -f ' . escapeshellarg($pattern)))));
    $total = 0.0;
    foreach ($pids as $pid) {
        $t = trim(shell(sprintf('ps -o time= -p %d', (int)$pid)));
        if ($t === '') {
            continue;
        }
        $parts = array_reverse(explode(':', $t));
        $sec   = 0.0;
        $mult  = 1.0;
        foreach ($parts as $p) {
            $sec  += (float)$p * $mult;
            $mult *= 60;
        }
        $total += $sec;
    }
    return $total;
}

function parseWrk(string $out): array
{
    $qps = 0.0; $p99 = 0.0; $reqs = 0; $err = 0; $non2xx = 0;
    if (preg_match('/Requests\/sec:\s+([\d.]+)/', $out, $m)) {
        $qps = (float)$m[1];
    }
    if (preg_match('/99%\s+([\d.]+)(us|ms|s)/', $out, $m)) {
        $v = (float)$m[1];
        $p99 = match ($m[2]) { 'us' => $v / 1000, 's' => $v * 1000, default => $v };
    }
    if (preg_match('/(\d+) requests in/', $out, $m)) {
        $reqs = (int)$m[1];
    }
    if (preg_match('/Socket errors:.*connect (\d+), read (\d+), write (\d+), timeout (\d+)/', $out, $m)) {
        $err = (int)$m[1] + (int)$m[2] + (int)$m[3] + (int)$m[4];
    }
    if (preg_match('/Non-2xx or 3xx responses:\s+(\d+)/', $out, $m)) {
        $non2xx = (int)$m[1];
    }
    return compact('qps', 'p99', 'reqs', 'err', 'non2xx');
}

function median(array $a): float
{
    sort($a);
    $n = count($a);
    if ($n === 0) {
        return 0.0;
    }
    return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2;
}

function rsd(array $a): float
{
    $n = count($a);
    if ($n < 2) {
        return 0.0;
    }
    $mean = array_sum($a) / $n;
    if ($mean == 0.0) {
        return 0.0;
    }
    $var = 0.0;
    foreach ($a as $v) {
        $var += ($v - $mean) ** 2;
    }
    return sqrt($var / ($n - 1)) / $mean * 100;
}

// ---------------------------------------------------------------- 执行

fwrite(STDERR, sprintf(
    "Gate 判定 | rounds=%d dur=%ds workers=%d wrk=-t%d -c%d\n\n",
    $rounds, $dur, WORKERS, THREADS, CONNS
));

$results = [];
foreach (IMPLS as $name => $meta) {
    $results[$name] = ['qps' => [], 'p99' => [], 'rss' => [], 'cpu_us' => [], 'err' => 0, 'non2xx' => 0, 'role' => $meta['role']];

    for ($r = 1; $r <= $rounds; $r++) {
        $port++;
        stopAll();
        if (!startServer($meta, $port, $dir)) {
            fwrite(STDERR, sprintf("  %-10s round %d  START-FAIL\n", $name, $r));
            continue;
        }
        // 预热
        shell(sprintf('wrk -t2 -c20 -d3s http://127.0.0.1:%d/ >/dev/null', $port));

        $cpu0 = cpuOf($meta['pattern']);
        $out  = shell(sprintf('wrk -t%d -c%d -d%ds --latency http://127.0.0.1:%d/', THREADS, CONNS, $dur, $port));
        $cpu1 = cpuOf($meta['pattern']);
        $rss  = rssOf($meta['pattern']);

        $m = parseWrk($out);
        if ($m['qps'] <= 0) {
            fwrite(STDERR, sprintf("  %-10s round %d  WRK-FAIL\n", $name, $r));
            continue;
        }
        $cpuUs = $m['reqs'] > 0 ? ($cpu1 - $cpu0) * 1e6 / $m['reqs'] : 0.0;

        $results[$name]['qps'][]    = $m['qps'];
        $results[$name]['p99'][]    = $m['p99'];
        $results[$name]['rss'][]    = $rss;
        $results[$name]['cpu_us'][] = $cpuUs;
        $results[$name]['err']     += $m['err'];
        $results[$name]['non2xx']  += $m['non2xx'];

        fwrite(STDERR, sprintf(
            "  %-10s round %d  QPS=%-11s P99=%-8s RSS=%-8s CPU=%.2fus/req\n",
            $name, $r, number_format($m['qps'], 0), $m['p99'] . 'ms', $rss . 'KB', $cpuUs
        ));
    }
}
stopAll();

// ---------------------------------------------------------------- 汇总

$agg = [];
foreach ($results as $name => $d) {
    if (!$d['qps']) {
        continue;
    }
    $agg[$name] = [
        'role'    => $d['role'],
        'qps'     => median($d['qps']),
        'qps_rsd' => rsd($d['qps']),
        'p99'     => median($d['p99']),
        'rss'     => median(array_map('floatval', $d['rss'])),
        'cpu_us'  => median($d['cpu_us']),
        'err'     => $d['err'],
        'non2xx'  => $d['non2xx'],
    ];
}

$base = $agg['workerman'] ?? null;
if ($base === null) {
    fwrite(STDERR, "\n基线 Workerman 数据缺失，无法判定。\n");
    exit(1);
}

echo "\n", str_repeat('=', 92), "\n";
printf("%-12s %-8s %-12s %-9s %-9s %-11s %-12s\n",
    'IMPL', 'ROLE', 'QPS(中位)', 'RSD%', 'P99(ms)', 'RSS(KB)', 'CPU(us/req)');
echo str_repeat('-', 92), "\n";
foreach ($agg as $name => $a) {
    printf("%-12s %-8s %-12s %-9s %-9s %-11s %-12s\n",
        $name, $a['role'], number_format($a['qps'], 0), number_format($a['qps_rsd'], 2),
        number_format($a['p99'], 2), number_format($a['rss'], 0), number_format($a['cpu_us'], 2));
}
echo str_repeat('=', 92), "\n\n";

$verdicts = [];
foreach ($agg as $name => $a) {
    if ($a['role'] !== 'candidate') {
        continue;
    }
    $g = [
        'G1 吞吐 >=1.30x' => [$a['qps'] >= $base['qps'] * GATE_QPS,
            sprintf('%.3fx (%s vs %s)', $a['qps'] / $base['qps'], number_format($a['qps'], 0), number_format($base['qps'], 0))],
        'G2 P99 不劣化'   => [$a['p99'] <= $base['p99'] + 1e-9,
            sprintf('%.2fms vs %.2fms', $a['p99'], $base['p99'])],
        'G3 RSD < 5%'     => [$a['qps_rsd'] < GATE_RSD, sprintf('%.2f%%', $a['qps_rsd'])],
        'G4 零错误'       => [$a['err'] === 0 && $a['non2xx'] === 0,
            sprintf('socket=%d non2xx=%d', $a['err'], $a['non2xx'])],
        'G5 内存 <=1.5x'  => [$a['rss'] <= $base['rss'] * GATE_MEM,
            sprintf('%.2fx (%s vs %s KB)', $base['rss'] > 0 ? $a['rss'] / $base['rss'] : 0,
                number_format($a['rss'], 0), number_format($base['rss'], 0))],
    ];
    $pass = true;
    echo "候选内核：{$name}\n";
    foreach ($g as $label => [$ok, $detail]) {
        printf("  [%s] %-18s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
        $pass = $pass && $ok;
    }
    $verdicts[$name] = $pass;
    printf("  => %s\n\n", $pass ? '✅ 全部通过：保留自研内核' : '❌ 未通过：降级为 Swoole/Workerman 兼容层');
}

$final = in_array(true, $verdicts, true);
echo "最终判定：", $final ? "保留自研网络 I/O 内核\n" : "不保留自研网络 I/O 内核 → 转为兼容层 + 进程编排内核\n";

file_put_contents(__DIR__ . '/gate-result.json', json_encode([
    'time'     => date('c'),
    'php'      => PHP_VERSION,
    'rounds'   => $rounds,
    'duration' => $dur,
    'workers'  => WORKERS,
    'wrk'      => ['threads' => THREADS, 'conns' => CONNS],
    'agg'      => $agg,
    'verdicts' => $verdicts,
    'final'    => $final,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

exit($final ? 0 : 2);
