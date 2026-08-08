<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Runtime\Driver\NativeRuntime;
use Kode\Process\Runtime\Driver\SwooleRuntime;
use Kode\Process\Runtime\Driver\WorkermanRuntime;
use PHPUnit\Framework\TestCase;

/**
 * 跨运行时请求契约一致性。
 *
 * 「切换底层运行时，业务代码零改动」这句承诺，只有在 handler 拿到的请求
 * 逐字段相同的时候才成立。这里用同一份不含任何运行时分支的 handler，
 * 在每个可用运行时上各起一个真实服务，发同一个请求，比对交付结果。
 *
 * 任何一个运行时的适配层退化（比如又把框架原生 Request 直接透传给业务），
 * 这个测试就会失败。
 */
final class RuntimeRequestContractTest extends TestCase
{
    /** 同一个请求在所有运行时上必须解析出完全一致的这些字段 */
    private const string HANDLER = <<<'PHP'
        static function ($conn, $request): void {
            $conn->send(json_encode([
                'class'     => is_object($request) ? get_class($request) : gettype($request),
                'method'    => $request->method(),
                'path'      => $request->path(),
                'raw_path'  => $request->rawPath(),
                'uri'       => $request->uri(),
                'protocol'  => $request->protocol(),
                'query_all' => $request->get(),
                'q_page'    => $request->get('page'),
                'h_ci'      => $request->header('X-TEST'),
                'h_keys'    => array_keys($request->headers()),
                'post_all'  => $request->post(),
                'body'      => $request->body(),
                'is_post'   => $request->isMethod('post'),
                'ct'        => $request->contentType(),
                'arr_path'  => $request['path'],
                'arr_get'   => $request['get'],
                'to_keys'   => array_keys($request->toArray()),
            ], JSON_UNESCAPED_SLASHES));
        }
        PHP;

    public function testAllAvailableRuntimesDeliverIdenticalRequests(): void
    {
        $available = ['native' => NativeRuntime::isAvailable()];
        if (SwooleRuntime::isAvailable()) {
            $available['swoole'] = true;
        }
        if (WorkermanRuntime::isAvailable()) {
            $available['workerman'] = true;
        }

        if (count($available) < 2) {
            $this->markTestSkipped('本机仅有一个可用运行时，无从比对契约');
        }

        $results = [];
        foreach (array_keys($available) as $runtime) {
            $results[$runtime] = $this->probe($runtime);
        }

        $baselineName = array_key_first($results);
        $baseline     = $results[$baselineName];

        // 契约本身是否正确
        $this->assertSame('Kode\Process\Http\Request', $baseline['class']);
        $this->assertSame('POST', $baseline['method']);
        $this->assertSame('/api/v1/items', $baseline['path'], '路径穿越串必须被拍平');
        $this->assertSame('/api/../api/v1/./items', $baseline['raw_path']);
        $this->assertSame(['page' => '2', 'kw' => 'a b'], $baseline['query_all']);
        $this->assertSame('kode-test', $baseline['h_ci'], 'header() 必须大小写不敏感');
        $this->assertSame(['x' => '1', 'y' => 'two'], $baseline['post_all']);
        $this->assertTrue($baseline['is_post']);
        $this->assertSame(
            ['method', 'uri', 'path', 'query', 'protocol', 'headers', 'body', 'get', 'post'],
            $baseline['to_keys']
        );
        $this->assertContains('x-test', $baseline['h_keys'], '头名必须统一小写');

        // 各运行时之间是否一致
        foreach ($results as $runtime => $actual) {
            $this->assertSame(
                $baseline,
                $actual,
                "运行时 {$runtime} 交付的请求与 {$baselineName} 不一致，业务代码无法零改动切换"
            );
        }
    }

    /**
     * 在指定运行时上起服务、发一个请求、取回 handler 序列化出来的字段。
     *
     * @return array<string, mixed>
     */
    private function probe(string $runtime): array
    {
        $port   = $this->freePort();
        $script = $this->writeServerScript($runtime, $port);
        $pid    = $this->spawn($script);

        try {
            $this->assertTrue($this->waitForPort($port), "{$runtime} 未能在超时内监听 {$port}");

            $response = $this->request($port);
            $split    = strpos($response, "\r\n\r\n");
            $this->assertNotFalse($split, "{$runtime} 返回的不是完整 HTTP 响应：{$response}");

            $body    = substr($response, $split + 4);
            $decoded = json_decode($body, true);
            $this->assertIsArray($decoded, "{$runtime} 返回的 body 不是 JSON：{$body}");

            // Host 头含端口，逐运行时不同，比对前剔除
            $decoded['h_keys'] = array_values(array_diff($decoded['h_keys'], ['host', 'user-agent']));
            sort($decoded['h_keys']);

            return $decoded;
        } finally {
            $this->terminate($pid);
            @unlink($script);
        }
    }

    private function writeServerScript(string $runtime, int $port): string
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $file     = sys_get_temp_dir() . "/kode-contract-{$runtime}-" . getmypid() . "-{$port}.php";
        $handler  = self::HANDLER;

        $code = <<<PHP
        <?php
        \$argv = [__FILE__, 'start'];
        \$_SERVER['argv'] = \$argv;
        require '{$autoload}';

        \$rt = \\Kode\\Process\\Runtime::make('{$runtime}');
        \$rt->listen('http://127.0.0.1:{$port}', ['workers' => 1, 'gzip' => false]);
        \$rt->on('message', {$handler});
        \$rt->start();
        PHP;

        file_put_contents($file, $code);

        return $file;
    }

    private function request(int $port): string
    {
        $body = 'x=1&y=two';
        $conn = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2.0);
        self::assertIsResource($conn, "连接失败：[{$errno}] {$errstr}");

        stream_set_timeout($conn, 3);
        fwrite($conn, sprintf(
            "POST /api/../api/v1/./items?page=2&kw=a%%20b HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "X-Test: kode-test\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . "Content-Length: %d\r\n"
            . "Connection: close\r\n\r\n%s",
            strlen($body),
            $body
        ));

        $response = stream_get_contents($conn);
        fclose($conn);

        return (string) $response;
    }

    private function spawn(string $script): int
    {
        $cmd = sprintf('%s %s >/dev/null 2>&1 & echo $!', escapeshellarg(PHP_BINARY), escapeshellarg($script));
        $pid = (int) shell_exec($cmd);
        $this->assertGreaterThan(0, $pid, '无法启动测试服务');

        return $pid;
    }

    private function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock);

        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private function waitForPort(int $port, float $timeout = 8.0): bool
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $conn = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                return true;
            }
            usleep(50_000);
        }

        return false;
    }

    private function terminate(int $pid): void
    {
        @posix_kill($pid, SIGTERM);

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline && @posix_kill($pid, 0)) {
            usleep(50_000);
        }

        if (@posix_kill($pid, 0)) {
            @posix_kill($pid, SIGKILL);
        }
    }
}
