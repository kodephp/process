<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Protocol\Http2\Hpack;

$enc = new Hpack();

// 典型请求头块（含 3 个 Huffman 字面量值）——复现 feed/respond 拆分里的「真实请求」
$blockLiteral = $enc->encode([
    [':method', 'GET'], [':scheme', 'http'], [':path', '/'], [':authority', 'example.com'],
    ['user-agent', 'curl/8.0'], ['accept', '*/*'],
]);

// 纯索引头块（method/scheme/path 全静态表索引，无 Huffman 解码）
$blockIndexed = $enc->encode([
    [':method', 'GET'], [':scheme', 'http'], [':path', '/'],
]);

function timeDecode(Hpack $d, string $block, int $n): float
{
    for ($i = 0; $i < 3000; $i++) {
        $d->decode($block);
    }
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $d->decode($block);
    }
    return (hrtime(true) - $t) / 1e6;
}

$n = 300000;
$dl = timeDecode(new Hpack(), $blockLiteral, $n);
$di = timeDecode(new Hpack(), $blockIndexed, $n);

printf("literal block (Huffman 解码) : %8.2f ms   %.3f µs/op\n", $dl, $dl * 1000 / $n);
printf("indexed block (无 Huffman)   : %8.2f ms   %.3f µs/op\n", $di, $di * 1000 / $n);
printf("Huffman 解码占比             : ~%.0f%%  (%.3f µs/op)\n", ($dl - $di) / $dl * 100, ($dl - $di) * 1000 / $n);
