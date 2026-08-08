<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Protocol\Http2\{Frame, Hpack, Http2Session};

$n = 100000;
$enc = new Hpack();
$block = $enc->encode([
    [':method', 'GET'], [':scheme', 'http'], [':path', '/'], [':authority', 'example.com'],
    ['user-agent', 'curl/8.0'], ['accept', '*/*'],
]);
$frames = [];
for ($sid = 1; $sid <= ($n + 4000) * 2; $sid += 2) {
    $frames[] = Frame::encode(Frame::TYPE_HEADERS, Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM, $sid, $block);
}
$typicalHeaders = [
    'content-type' => 'text/html; charset=utf-8', 'content-length' => '13',
    'cache-control' => 'no-cache', 'date' => 'Mon, 08 Aug 2026 00:00:00 GMT', 'server' => 'kode/5.2.4',
];
$body = 'Hello, Kode!';

// ---- feed 计时：每条请求收完后立即 resetStream 释放（reset 仅写几字节，可忽略）；
//      用连续递增的流 ID，warmup 与计时区共用一个 $k
$s = new Http2Session();
$s->markPrefaceReceived();
$s->sendLocalSettings();
$k = 0;
for ($i = 0; $i < 2000; $i++) {
    $s->feed($frames[$k]);
    $s->resetStream($k * 2 + 1);
    $k++;
}
$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $s->feed($frames[$k]);
    $s->resetStream($k * 2 + 1);
    $k++;
}
$feedMs = (hrtime(true) - $t) / 1e6;

// ---- respond 计时：feed 不计入计时，只对 respond() 计时（每轮 hrtime 约 20ns，占比 <5%）
$s2 = new Http2Session();
$s2->markPrefaceReceived();
$s2->sendLocalSettings();
$k = 0;
$respNs = 0;
for ($i = 0; $i < $n; $i++) {
    $s2->feed($frames[$k]);                 // 开流（不计时）
    $sid = $k * 2 + 1;
    $a = hrtime(true);
    $s2->respond($sid, 200, $typicalHeaders, $body);
    $b = hrtime(true);
    $respNs += $b - $a;
    $s2->feed(Frame::windowUpdate(0, strlen($body)));  // 维持窗口（不计时）
    $k++;
}
$respMs = $respNs / 1e6;

printf("feed only (decode+completeHeaders): %9.2f ms  %.0f ops/s\n", $feedMs, $n / ($feedMs / 1000));
printf("respond only (encode+frame):        %9.2f ms  %.0f ops/s\n", $respMs, $n / ($respMs / 1000));
