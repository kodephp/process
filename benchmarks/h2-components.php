<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Protocol\Http2\{Frame, Hpack};

$n = 500000;

// 典型请求头块（客户端→服务端，带 Huffman 字面量）
$enc = new Hpack();
$reqBlock = $enc->encode([
    [':method', 'GET'], [':scheme', 'http'], [':path', '/'], [':authority', 'example.com'],
    ['user-agent', 'curl/8.0'], ['accept', '*/*'],
]);

// 典型响应头块（服务端→客户端）
$respBlock = $enc->encode([
    [':status', '200'],
    ['content-type', 'text/html; charset=utf-8'], ['content-length', '13'],
    ['cache-control', 'no-cache'], ['date', 'Mon, 08 Aug 2026 00:00:00 GMT'], ['server', 'kode/5.2.4'],
]);

// 1) Frame::decode（单帧）
$frameBytes = Frame::encode(Frame::TYPE_HEADERS, Frame::FLAG_END_HEADERS | Frame::FLAG_END_STREAM, 1, $reqBlock);
$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    Frame::decode($frameBytes, 0, 16384);
}
$msFrameDecode = (hrtime(true) - $t) / 1e6;

// 2) Frame::encode（单帧）
$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    Frame::encode(Frame::TYPE_HEADERS, Frame::FLAG_END_HEADERS, ($i * 2 + 1), $respBlock);
}
$msFrameEncode = (hrtime(true) - $t) / 1e6;

// 3) Hpack::decode（请求头块）
$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $enc->decode($reqBlock);
}
$msHpackDecode = (hrtime(true) - $t) / 1e6;

// 4) Hpack::encode（响应头列表，含字面量缓存命中）
$t = hrtime(true);
$list = [
    [':status', '200'],
    ['content-type', 'text/html; charset=utf-8'], ['content-length', '13'],
    ['cache-control', 'no-cache'], ['date', 'Mon, 08 Aug 2026 00:00:00 GMT'], ['server', 'kode/5.2.4'],
];
for ($i = 0; $i < $n; $i++) {
    $enc->encode($list);
}
$msHpackEncode = (hrtime(true) - $t) / 1e6;

printf("Frame::decode  (1 frame)    : %9.2f ms  %8.0f ops/s\n", $msFrameDecode, $n / ($msFrameDecode / 1000));
printf("Frame::encode  (1 frame)    : %9.2f ms  %8.0f ops/s\n", $msFrameEncode, $n / ($msFrameEncode / 1000));
printf("Hpack::decode  (req block)  : %9.2f ms  %8.0f ops/s\n", $msHpackDecode, $n / ($msHpackDecode / 1000));
printf("Hpack::encode  (resp list)  : %9.2f ms  %8.0f ops/s\n", $msHpackEncode, $n / ($msHpackEncode / 1000));
printf("--------------------------------------------------\n");
printf("total pure-fn (decode side) : %9.2f ms\n", $msFrameDecode + $msHpackDecode);
printf("total pure-fn (encode side) : %9.2f ms\n", $msFrameEncode + $msHpackEncode);
