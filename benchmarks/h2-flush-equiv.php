<?php

declare(strict_types=1);

/**
 * flushPending() 改写前后的线格式对拍：跑一批覆盖窗口、分片、并发流的场景，
 * 输出所有 drain() 字节的确定性摘要。改写正确当且仅当摘要与改写前完全一致。
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Protocol\Http2\Frame;
use Kode\Process\Protocol\Http2\Hpack;
use Kode\Process\Protocol\Http2\Http2Session;

/** 建一条握手完毕的会话 */
function mkSession(int $initWin = 1048576, int $maxFrame = Frame::MIN_MAX_FRAME_SIZE): Http2Session
{
    $s = new Http2Session(256, $initWin, $maxFrame);
    $s->feed(Frame::PREFACE);
    $s->drain();

    return $s;
}

function openStream(Http2Session $s, Hpack $c, int $id, string $method = 'GET'): void
{
    $s->feed(Frame::encode(
        Frame::TYPE_HEADERS,
        Frame::FLAG_END_HEADERS | ($method === 'GET' ? Frame::FLAG_END_STREAM : 0),
        $id,
        $c->encode([
            [':method', $method],
            [':scheme', 'http'],
            [':path', '/p' . $id],
            [':authority', 'example.com'],
        ])
    ));
}

$out = [];

// ---- 场景 1：默认窗口下发不同体积的响应（含窗口耗尽 → 分批 WINDOW_UPDATE 续发） ----
foreach ([0, 1, 100, 16383, 16384, 16385, 65535, 65536, 300000] as $size) {
    $c = new Hpack();
    $s = mkSession();
    openStream($s, $c, 1);
    $s->drain();

    $s->respond(1, 200, ['content-type' => 'text/plain'], str_repeat('a', $size));
    $out[] = 's1.' . $size . '.a=' . bin2hex(hash('sha256', $s->drain(), true));

    // 分多次回补窗口，逼出「窗口耗尽 → 部分发送 → 再续」的路径
    for ($i = 0; $i < 12; $i++) {
        $s->feed(Frame::windowUpdate(0, 12345));
        $s->feed(Frame::windowUpdate(1, 12345));
        $out[] = 's1.' . $size . '.w' . $i . '=' . bin2hex(hash('sha256', $s->drain(), true));
    }
    $out[] = 's1.' . $size . '.stats=' . json_encode($s->stats());
}

// ---- 场景 2：writeData 流式分片（含空片、末片带 end、单独 end） ----
foreach ([[7, 3], [16384, 5], [1000, 40]] as [$chunk, $times]) {
    $c = new Hpack();
    $s = mkSession();
    openStream($s, $c, 1);
    $s->drain();

    $s->respondHeaders(1, 200, ['x-a' => 'b']);
    for ($i = 0; $i < $times; $i++) {
        $s->writeData(1, str_repeat('b', $chunk));
        $out[] = "s2.$chunk.$i=" . bin2hex(hash('sha256', $s->drain(), true));
        $s->feed(Frame::windowUpdate(0, $chunk));
        $s->feed(Frame::windowUpdate(1, $chunk));
        $out[] = "s2.$chunk.$i.w=" . bin2hex(hash('sha256', $s->drain(), true));
    }
    $s->writeData(1, '');            // 空片
    $s->writeData(1, 'tail', true);  // 末片带 END_STREAM
    $out[] = "s2.$chunk.end=" . bin2hex(hash('sha256', $s->drain(), true));
    $out[] = "s2.$chunk.stats=" . json_encode($s->stats());
}

// ---- 场景 3：多流交错 + 只回补连接级窗口（考验遍历顺序与公平性） ----
$c = new Hpack();
$s = mkSession(65535);
for ($i = 1; $i <= 9; $i += 2) {
    openStream($s, $c, $i);
}
$s->drain();
for ($i = 1; $i <= 9; $i += 2) {
    $s->respond($i, 200, ['x-i' => (string) $i], str_repeat((string) $i, 40000));
}
$out[] = 's3.init=' . bin2hex(hash('sha256', $s->drain(), true));
for ($r = 0; $r < 20; $r++) {
    $s->feed(Frame::windowUpdate(0, 20000));
    for ($i = 1; $i <= 9; $i += 2) {
        $s->feed(Frame::windowUpdate($i, 20000));
    }
    $out[] = "s3.r$r=" . bin2hex(hash('sha256', $s->drain(), true));
}
$out[] = 's3.stats=' . json_encode($s->stats());

// ---- 场景 4：发送中途被 RST_STREAM 打断（索引摘除路径） ----
$c = new Hpack();
$s = mkSession(65535);
openStream($s, $c, 1);
openStream($s, $c, 3);
$s->drain();
$s->respond(1, 200, [], str_repeat('c', 200000));
$s->respond(3, 200, [], str_repeat('d', 200000));
$out[] = 's4.init=' . bin2hex(hash('sha256', $s->drain(), true));
$s->feed(Frame::rstStream(1, Frame::ERROR_CANCEL));
$out[] = 's4.rst=' . bin2hex(hash('sha256', $s->drain(), true));
$s->feed(Frame::windowUpdate(0, 500000));
$s->feed(Frame::windowUpdate(3, 500000));
$out[] = 's4.after=' . bin2hex(hash('sha256', $s->drain(), true));
$out[] = 's4.stats=' . json_encode($s->stats());

// ---- 场景 5：对端声明更大的 maxFrameSize（切片粒度变化） ----
foreach ([16384, 32768, 65535] as $peerMax) {
    $c = new Hpack();
    $s = mkSession();
    // 对端 SETTINGS：MAX_FRAME_SIZE + INITIAL_WINDOW_SIZE
    $s->feed(Frame::encode(Frame::TYPE_SETTINGS, 0, 0, pack('nN', 0x5, $peerMax) . pack('nN', 0x4, 1048576)));
    openStream($s, $c, 1);
    $s->drain();
    $s->respond(1, 200, [], str_repeat('e', 500000));
    $out[] = "s5.$peerMax=" . bin2hex(hash('sha256', $s->drain(), true));
    $s->feed(Frame::windowUpdate(0, 500000));
    $out[] = "s5.$peerMax.w=" . bin2hex(hash('sha256', $s->drain(), true));
    $out[] = "s5.$peerMax.stats=" . json_encode($s->stats());
}

$body = implode("\n", $out);
echo $body, "\n";
echo str_repeat('=', 60), "\n";
echo 'DIGEST: ', hash('sha256', $body), "\n";
