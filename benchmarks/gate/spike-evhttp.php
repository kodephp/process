<?php
/**
 * Spike B：EventHttp —— libevent 内置 HTTP 服务器，请求解析全在 C 层
 */
$workers = (int)(getenv('BENCH_W') ?: 4);
$port    = (int)(getenv('BENCH_PORT') ?: 9502);

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
if (defined('SO_REUSEPORT')) @socket_set_option($sock, SOL_SOCKET, SO_REUSEPORT, 1);
socket_bind($sock, '0.0.0.0', $port);
socket_listen($sock, 65535);
socket_set_nonblock($sock);

$pids = [];
for ($i = 0; $i < $workers; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) { worker($sock); exit(0); }
    $pids[] = $pid;
}
foreach ($pids as $p) pcntl_waitpid($p, $st);

function worker($sock): void
{
    $base = new EventBase();
    $http = new EventHttp($base);
    $http->setDefaultCallback(function (EventHttpRequest $req) {
        $buf = new EventBuffer();
        $buf->add('Hello World');
        $req->addHeader('Content-Type', 'text/plain', EventHttpRequest::OUTPUT_HEADER);
        $req->sendReply(200, 'OK', $buf);
    });
    $http->accept($sock);
    $base->loop();
}
