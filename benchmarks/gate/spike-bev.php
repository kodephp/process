<?php
/**
 * Spike A：EventListener(C层accept) + EventBufferEvent(C层读写缓冲) + 极简 HTTP
 */
$workers = (int)(getenv('BENCH_W') ?: 4);
$port    = (int)(getenv('BENCH_PORT') ?: 9502);

// master 创建 listen socket，fork 后共享（与 Workerman 默认策略一致）
$ctx = stream_context_create(['socket' => ['backlog' => 65535, 'so_reuseport' => 1]]);
$srv = stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$srv) { fwrite(STDERR, "bind fail: $errstr\n"); exit(1); }
stream_set_blocking($srv, false);

$pids = [];
for ($i = 0; $i < $workers; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) { worker($srv); exit(0); }
    $pids[] = $pid;
}
foreach ($pids as $p) pcntl_waitpid($p, $st);

function worker($srv): void
{
    $base = new EventBase();
    // 预构建响应模板，避免每请求拼接
    $body = 'Hello World';
    $resp = "HTTP/1.1 200 OK\r\nServer: kode\r\nContent-Type: text/plain\r\nContent-Length: "
          . strlen($body) . "\r\nConnection: keep-alive\r\n\r\n" . $body;

    $conns = [];
    $onAccept = function ($listener, $fd, $addr, $ctx) use ($base, $resp, &$conns) {
        $bev = new EventBufferEvent($base, $fd, EventBufferEvent::OPT_CLOSE_ON_FREE);
        $id = spl_object_id($bev);
        $conns[$id] = $bev;
        $bev->setCallbacks(
            function (EventBufferEvent $bev) use ($resp) {
                $in = $bev->input;
                $n  = $in->length;
                if ($n < 4) return;
                $buf = $in->read($n);
                // 极简：统计完整请求数（\r\n\r\n 结尾），支持 pipelining
                $cnt = substr_count($buf, "\r\n\r\n");
                if ($cnt > 0) {
                    $bev->write($cnt === 1 ? $resp : str_repeat($resp, $cnt));
                }
            },
            null,
            function (EventBufferEvent $bev, int $events) use (&$conns) {
                if ($events & (EventBufferEvent::ERROR | EventBufferEvent::EOF)) {
                    unset($conns[spl_object_id($bev)]);
                    $bev->free();
                }
            }
        );
        $bev->enable(Event::READ | Event::WRITE);
    };

    $listener = new EventListener($base, $onAccept, null,
        EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE, -1, $srv);
    $listener->setErrorCallback(function () {});
    $base->loop();
}
