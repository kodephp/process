<?php

declare(strict_types=1);

namespace Kode\Process\Http;

use Kode\Process\Protocol\HttpProtocol;
use Psr\Http\Message\ResponseInterface;

/**
 * 把 PSR-7 响应对象序列化为完整 HTTP/1.1 报文字节。
 *
 * 这是传输层能力（与 {@see HttpProtocol::encode} 同层），用途是把应用层
 * `Psr\Http\Message\ResponseInterface`（来自 kode/http 等 PSR-7 实现）桥接回
 * 当前连接，让同一份 handler 在 Native / Swoole / Workerman 三种运行时下都能
 * 把一个 PSR-7 响应写出去，业务零改动。
 *
 * 复用了 {@see HttpProtocol} 的两处硬化点，保持与包内其它响应路径一致：
 *  - `headerLine()`：剔除头名/头值中的 CR / LF / NUL，防 HTTP 响应拆分；
 *  - `getStatusText()`：PSR-7 未提供原因短语时回退到标准原因文本。
 *
 * 同名多值头（如多个 `Set-Cookie`）逐条输出为独立头行，符合 HTTP 语义。
 * gzip 压缩会跳过原始 `Content-Length` / `Content-Encoding`，改写为压缩后的值；
 * 压缩失败时安全回退为非压缩响应，绝不抛异常把一次数据问题升级为连接级故障。
 */
final class Psr7Response
{
    /**
     * 将 PSR-7 响应序列化为完整 HTTP 报文字节。
     *
     * @param bool $gzip 为 true 时对响应体做 gzip 压缩（Content-Encoding: gzip），
     *                    同时覆盖原始 Content-Length / Content-Encoding；
     *                    为 false（或响应已带 Content-Encoding）时原样输出，
     *                    缺失则自动补 Content-Length。
     */
    public static function toHttp11(ResponseInterface $response, bool $gzip = false): string
    {
        $status = $response->getStatusCode();
        $reason = $response->getReasonPhrase();
        if ($reason === '') {
            $reason = HttpProtocol::getStatusText($status);
        }

        $protocol = $response->getProtocolVersion();
        if ($protocol === '') {
            $protocol = '1.1';
        }

        $body = (string) $response->getBody();

        // 响应已自带 Content-Encoding（如已被上层压缩）时不二次压缩，避免 gzip-of-gzip。
        $hasContentEncoding = $response->hasHeader('Content-Encoding')
            || $response->hasHeader('content-encoding');

        $doGzip = $gzip && !$hasContentEncoding;
        $compressed = '';
        if ($doGzip) {
            $compressed = @gzencode($body, -1);
            if ($compressed === false || $compressed === '') {
                $doGzip = false; // 压缩失败安全回退
            }
        }

        $head = 'HTTP/' . $protocol . ' ' . $status . ' ' . $reason . "\r\n";

        $hasContentLength = false;

        foreach ($response->getHeaders() as $name => $values) {
            $lower = strtolower((string) $name);

            // gzip 下这两个头由本方法重写，原始声明一律跳过
            if ($doGzip && ($lower === 'content-length' || $lower === 'content-encoding')) {
                continue;
            }
            if ($lower === 'content-length') {
                $hasContentLength = true;
            }

            // PSR-7 getHeaders() 返回 name => list<string>，同名多值头逐条输出
            foreach ($values as $value) {
                $head .= HttpProtocol::headerLine((string) $name, (string) $value);
            }
        }

        if ($doGzip) {
            $head .= HttpProtocol::headerLine('Content-Encoding', 'gzip');
            $head .= HttpProtocol::headerLine('Content-Length', (string) strlen($compressed));
            $outBody = $compressed;
        } else {
            if (!$hasContentLength) {
                $head .= HttpProtocol::headerLine('Content-Length', (string) strlen($body));
            }
            $outBody = $body;
        }

        return $head . "\r\n" . $outBody;
    }

    /**
     * 估算 PSR-7 响应体字节数（已知大小优先，未知时回退到字符串长度）。
     *
     * 供连接层决定是否满足自动 gzip 的体量阈值，避免在热路径上反复把流读成字符串。
     */
    public static function bodySize(ResponseInterface $response): int
    {
        $body = $response->getBody();
        $size = $body->getSize();

        return $size !== null ? $size : strlen((string) $body);
    }
}
