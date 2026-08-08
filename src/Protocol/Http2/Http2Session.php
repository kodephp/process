<?php

declare(strict_types=1);

namespace Kode\Process\Protocol\Http2;

/**
 * HTTP/2 服务端连接会话（RFC 7540）。
 *
 * 一条 TCP 连接对应一个 Session，负责：
 *  - 连接前奏与 SETTINGS 交换
 *  - 帧解析与分发、HEADERS/CONTINUATION 拼接、请求组装
 *  - 流状态机与并发流上限
 *  - 连接级 + 流级双层流控（收发两个方向）
 *  - PING / GOAWAY / RST_STREAM / WINDOW_UPDATE 处理
 *
 * **本类不碰套接字**：`feed()` 吃进收到的字节并返回已完整的请求，待发送数据攒在
 * 内部缓冲里由 `drain()` 取走。IO 与事件循环交给运行时，因此会话逻辑可以脱离
 * 网络单元测试，也能被任意运行时复用。
 */
final class Http2Session
{
    private const string STATE_IDLE      = 'idle';
    private const string STATE_OPEN      = 'open';
    private const string STATE_HALF_CLOSED_REMOTE = 'half-closed-remote';
    private const string STATE_CLOSED    = 'closed';

    /** 本端通告的设置 */
    private int $maxConcurrentStreams;

    private int $initialWindowSize;

    private int $maxFrameSize;

    /** 对端通告的设置（发送方向受其约束） */
    private int $peerInitialWindowSize = Frame::DEFAULT_WINDOW_SIZE;

    private int $peerMaxFrameSize = Frame::MIN_MAX_FRAME_SIZE;

    private bool $prefaceReceived = false;

    private bool $localSettingsSent = false;

    private bool $goawaySent = false;

    private bool $closed = false;

    private string $inBuffer = '';

    private string $outBuffer = '';

    /** 连接级发送窗口（对端允许我方发多少） */
    private int $sendWindow = Frame::DEFAULT_WINDOW_SIZE;

    /** 连接级接收窗口（我方允许对端发多少） */
    private int $recvWindow = Frame::DEFAULT_WINDOW_SIZE;

    /**
     * 活动流。
     *
     * @var array<int, array{
     *     state: string, headers: array<string, string>, pseudo: array<string, string>,
     *     body: string, sendWindow: int, recvWindow: int, pending: string,
     *     endAfterPending: bool, headersSent: bool
     * }>
     */
    private array $streams = [];

    /** HEADERS/CONTINUATION 拼接中的流 ID，0 表示当前不在头块序列中 */
    private int $continuationStream = 0;

    private string $continuationBuffer = '';

    private bool $continuationEndStream = false;

    /** HEADERS/CONTINUATION 序列已收到的帧数（含首帧 HEADERS），用于洪泛防护 */
    private int $continuationFrames = 0;

    /**
     * 单条头块序列允许的最大压缩字节数（CONTINUATION 洪泛防护，CVE-2024-27316 同类）。
     * 超过即视为攻击：RST_STREAM(PROTOCOL_ERROR) 并丢弃，避免 continuationBuffer 无限增长。
     */
    private const int MAX_HEADER_BLOCK_SIZE = 65536;

    /** 单条头块序列允许的最大 CONTINUATION 帧数（同上，防止帧数洪泛） */
    private const int MAX_CONTINUATION_FRAMES = 16;

    private int $lastStreamId = 0;

    private int $highestClientStream = 0;

    private readonly Hpack $decoder;

    private readonly Hpack $encoder;

    /**
     * @param int $maxConcurrentStreams 本端允许的并发流上限
     * @param int $initialWindowSize    本端通告的初始接收窗口
     * @param int $maxFrameSize         本端可接收的最大帧长
     */
    public function __construct(
        int $maxConcurrentStreams = 128,
        int $initialWindowSize = 1048576,
        int $maxFrameSize = Frame::MIN_MAX_FRAME_SIZE,
        int $headerTableSize = 4096,
    ) {
        $this->maxConcurrentStreams = max(1, $maxConcurrentStreams);
        $this->initialWindowSize    = min(max(Frame::DEFAULT_WINDOW_SIZE, $initialWindowSize), Frame::MAX_WINDOW_SIZE);
        $this->maxFrameSize         = min(max(Frame::MIN_MAX_FRAME_SIZE, $maxFrameSize), Frame::MAX_MAX_FRAME_SIZE);
        $this->recvWindow           = $this->initialWindowSize;
        $this->decoder              = new Hpack($headerTableSize);
        $this->encoder              = new Hpack($headerTableSize);
    }

    /**
     * 供直连（prior knowledge）以外的入口使用：h2c 升级时前奏由客户端在
     * 101 响应之后补发，此处允许运行时预置「已见到前奏」的状态。
     */
    public function markPrefaceReceived(): void
    {
        $this->prefaceReceived = true;
    }

    /** 是否已收齐连接前奏 */
    public function isPrefaceReceived(): bool
    {
        return $this->prefaceReceived;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** 当前活动流数量 */
    public function activeStreams(): int
    {
        return count($this->streams);
    }

    /**
     * 立刻把本端 SETTINGS 放入发送缓冲（连接建立后应尽早调用）。
     */
    public function sendLocalSettings(): void
    {
        if ($this->localSettingsSent) {
            return;
        }
        $this->localSettingsSent = true;

        $this->outBuffer .= Frame::settings([
            Frame::SETTINGS_MAX_CONCURRENT_STREAMS => $this->maxConcurrentStreams,
            Frame::SETTINGS_INITIAL_WINDOW_SIZE    => $this->initialWindowSize,
            Frame::SETTINGS_MAX_FRAME_SIZE         => $this->maxFrameSize,
            Frame::SETTINGS_ENABLE_PUSH            => 0,
        ]);

        // 连接级接收窗口若大于协议默认值，需要显式补齐差额
        $delta = $this->initialWindowSize - Frame::DEFAULT_WINDOW_SIZE;
        if ($delta > 0) {
            $this->outBuffer .= Frame::windowUpdate(0, $delta);
        }
    }

    /**
     * 应用 h2c 升级请求里的 `HTTP2-Settings`（base64url 的 SETTINGS 负载）。
     *
     * 非法值按「忽略」处理而非断开：升级握手阶段客户端实现差异较大，
     * 宽进严出比直接拒绝更稳。
     */
    public function applyUpgradeSettings(string $base64url): void
    {
        if ($base64url === '') {
            return;
        }

        $payload = base64_decode(strtr(trim($base64url), '-_', '+/'), true);
        if ($payload === false || $payload === '' || strlen($payload) % 6 !== 0) {
            return;
        }

        try {
            $this->applySettings($payload);
        } catch (Http2Exception) {
            // 升级阶段的非法设置忽略，后续正式 SETTINGS 帧仍会严格校验
        }
    }

    /**
     * h2c 升级：把触发升级的那条 HTTP/1.1 请求接管为流 1。
     *
     * RFC 7540 §3.2：服务端回 101 之后，原请求视为客户端在流 1 上发起且已
     * END_STREAM 的请求，客户端随后才补发连接前奏。因此这里只建流、不等前奏。
     *
     * @param array<string, mixed> $request HTTP/1.1 解析结果
     * @return array{stream: int, request: array<string, mixed>}
     */
    public function adoptUpgradedRequest(array $request): array
    {
        $headers = [];
        foreach ((array)($request['headers'] ?? []) as $name => $value) {
            $lower = strtolower((string)$name);
            // 逐跳头在 HTTP/2 中被禁止，升级后必须剥离（RFC 7540 §8.1.2.2）
            if (in_array($lower, ['connection', 'upgrade', 'http2-settings', 'keep-alive', 'proxy-connection', 'transfer-encoding'], true)) {
                continue;
            }
            $headers[$lower] = (string)$value;
        }

        $this->highestClientStream = 1;
        $this->lastStreamId        = 1;

        $this->streams[1] = [
            'state'           => self::STATE_HALF_CLOSED_REMOTE,
            'headers'         => $headers,
            'pseudo'          => [
                ':method'    => (string)($request['method'] ?? 'GET'),
                ':path'      => (string)($request['uri'] ?? '/'),
                ':scheme'    => 'http',
                ':authority' => $headers['host'] ?? '',
            ],
            'body'            => (string)($request['body'] ?? ''),
            'sendWindow'      => $this->peerInitialWindowSize,
            'recvWindow'      => $this->initialWindowSize,
            'pending'         => '',
            'endAfterPending' => false,
            'headersSent'     => false,
        ];

        return ['stream' => 1, 'request' => $this->buildRequest(1)];
    }

    /**
     * 吃进收到的字节，返回本轮组装完成的请求。
     *
     * @return list<array{stream: int, request: array<string, mixed>}>
     * @throws Http2Exception 连接级协议错误（调用方应发 GOAWAY 后断开）
     */
    public function feed(string $data): array
    {
        if ($this->closed) {
            return [];
        }

        $this->inBuffer .= $data;

        if (!$this->prefaceReceived) {
            if (strlen($this->inBuffer) < Frame::PREFACE_SIZE) {
                // 已收到的部分必须是前奏的前缀，否则不是 h2 连接
                if (!str_starts_with(Frame::PREFACE, $this->inBuffer)) {
                    throw Http2Exception::protocol('无效的 HTTP/2 连接前奏');
                }
                return [];
            }
            if (!str_starts_with($this->inBuffer, Frame::PREFACE)) {
                throw Http2Exception::protocol('无效的 HTTP/2 连接前奏');
            }
            $this->inBuffer        = substr($this->inBuffer, Frame::PREFACE_SIZE);
            $this->prefaceReceived = true;
            $this->sendLocalSettings();
        }

        $requests = [];

        while (true) {
            $frame = Frame::decode($this->inBuffer, 0, $this->maxFrameSize);
            if ($frame === null) {
                break;
            }
            $this->inBuffer = substr($this->inBuffer, $frame['size']);

            try {
                $request = $this->dispatch($frame);
            } catch (Http2Exception $e) {
                // 流级错误只重置该流，连接与其它流照常服务（RFC 7540 §5.4.2）；
                // 连接级错误交由调用方发 GOAWAY 并断开。
                if (!$e->isStreamError()) {
                    throw $e;
                }
                $this->resetStream($e->streamId(), $e->errorCode());
                continue;
            }

            if ($request !== null) {
                $requests[] = $request;
            }
            if ($this->closed) {
                break;
            }
        }

        return $requests;
    }

    /**
     * 分发单帧。
     *
     * @param array{type: int, flags: int, stream: int, payload: string, size: int} $frame
     * @return array{stream: int, request: array<string, mixed>}|null 组装完成的请求
     */
    private function dispatch(array $frame): ?array
    {
        $type = $frame['type'];

        // 头块序列不可被任何其它帧打断（RFC 7540 §6.10）
        if ($this->continuationStream !== 0 && $type !== Frame::TYPE_CONTINUATION) {
            throw Http2Exception::protocol('头块序列被 ' . Frame::typeName($type) . ' 打断');
        }

        return match ($type) {
            Frame::TYPE_HEADERS       => $this->onHeaders($frame),
            Frame::TYPE_CONTINUATION  => $this->onContinuation($frame),
            Frame::TYPE_DATA          => $this->onData($frame),
            Frame::TYPE_SETTINGS      => $this->onSettings($frame),
            Frame::TYPE_WINDOW_UPDATE => $this->onWindowUpdate($frame),
            Frame::TYPE_PING          => $this->onPing($frame),
            Frame::TYPE_RST_STREAM    => $this->onRstStream($frame),
            Frame::TYPE_GOAWAY        => $this->onGoaway(),
            Frame::TYPE_PRIORITY      => $this->onPriority($frame),
            Frame::TYPE_PUSH_PROMISE  => throw Http2Exception::protocol('服务端不接受 PUSH_PROMISE'),
            // 未知帧类型必须忽略（RFC 7540 §4.1），保证前向兼容
            default                   => null,
        };
    }

    // -------------------------------------------------------------- HEADERS

    /**
     * @param array{type: int, flags: int, stream: int, payload: string, size: int} $frame
     * @return array{stream: int, request: array<string, mixed>}|null
     */
    private function onHeaders(array $frame): ?array
    {
        $streamId = $frame['stream'];
        if ($streamId === 0) {
            throw Http2Exception::protocol('HEADERS 的流 ID 不能为 0');
        }
        if (($streamId & 1) === 0) {
            throw Http2Exception::protocol('客户端发起的流 ID 必须为奇数');
        }

        $payload = $frame['payload'];
        if (($frame['flags'] & Frame::FLAG_PADDED) !== 0) {
            $payload = Frame::stripPadding($payload, $streamId);
        }
        if (($frame['flags'] & Frame::FLAG_PRIORITY) !== 0) {
            if (strlen($payload) < 5) {
                throw Http2Exception::frameSize('带 PRIORITY 的 HEADERS 负载过短', $streamId);
            }
            $payload = substr($payload, 5); // 优先级信息不参与调度，直接丢弃
        }

        if (isset($this->streams[$streamId])) {
            // 已存在的流再收 HEADERS 只可能是 trailers，本实现不支持带内容的 trailers
            throw Http2Exception::protocol('不支持 trailers', $streamId);
        }
        if ($streamId <= $this->highestClientStream) {
            throw Http2Exception::protocol('流 ID 必须单调递增');
        }
        if (count($this->streams) >= $this->maxConcurrentStreams) {
            $this->outBuffer .= Frame::rstStream($streamId, Frame::ERROR_REFUSED_STREAM);
            return null;
        }

        $this->highestClientStream = $streamId;
        $this->lastStreamId        = $streamId;

        $this->streams[$streamId] = [
            'state'           => self::STATE_OPEN,
            'headers'         => [],
            'pseudo'          => [],
            'body'            => '',
            'sendWindow'      => $this->peerInitialWindowSize,
            'recvWindow'      => $this->initialWindowSize,
            'pending'         => '',
            'endAfterPending' => false,
            'headersSent'     => false,
        ];

        $endStream = ($frame['flags'] & Frame::FLAG_END_STREAM) !== 0;

        if (($frame['flags'] & Frame::FLAG_END_HEADERS) === 0) {
            // 头块序列起步：单帧已超上限直接拒绝（洪泛防护）
            if (strlen($payload) > self::MAX_HEADER_BLOCK_SIZE) {
                $this->abortHeaderBlock($streamId);
                return null;
            }
            $this->continuationStream    = $streamId;
            $this->continuationBuffer    = $payload;
            $this->continuationEndStream = $endStream;
            $this->continuationFrames    = 1;
            return null;
        }

        return $this->completeHeaders($streamId, $payload, $endStream);
    }

    /**
     * @param array{type: int, flags: int, stream: int, payload: string, size: int} $frame
     * @return array{stream: int, request: array<string, mixed>}|null
     */
    private function onContinuation(array $frame): ?array
    {
        if ($this->continuationStream === 0 || $frame['stream'] !== $this->continuationStream) {
            throw Http2Exception::protocol('孤立的 CONTINUATION 帧');
        }

        $this->continuationBuffer  .= $frame['payload'];
        $this->continuationFrames  += 1;

        // 头块体积或帧数越界：判定为 CONTINUATION 洪泛，RST 该流并丢弃，不再累积。
        if (strlen($this->continuationBuffer) > self::MAX_HEADER_BLOCK_SIZE
            || $this->continuationFrames > self::MAX_CONTINUATION_FRAMES) {
            $this->abortHeaderBlock($this->continuationStream);
            return null;
        }

        if (($frame['flags'] & Frame::FLAG_END_HEADERS) === 0) {
            return null;
        }

        $streamId  = $this->continuationStream;
        $block     = $this->continuationBuffer;
        $endStream = $this->continuationEndStream;

        $this->continuationStream    = 0;
        $this->continuationBuffer    = '';
        $this->continuationEndStream = false;
        $this->continuationFrames    = 0;

        return $this->completeHeaders($streamId, $block, $endStream);
    }

    /**
     * 头块序列越界（体积 / 帧数）：RST_STREAM(PROTOCOL_ERROR) 该流并复位拼接状态，
     * 防止 continuationBuffer 无限增长导致内存耗尽（CONTINUATION 洪泛，CVE-2024-27316）。
     */
    private function abortHeaderBlock(int $streamId): void
    {
        $this->outBuffer           .= Frame::rstStream($streamId, Frame::ERROR_PROTOCOL);
        $this->continuationStream   = 0;
        $this->continuationBuffer   = '';
        $this->continuationEndStream = false;
        $this->continuationFrames   = 0;
        unset($this->streams[$streamId]);
    }

    /**
     * 头块收齐：HPACK 解码 → 校验伪头 → 若同时 END_STREAM 则请求已完整。
     *
     * @return array{stream: int, request: array<string, mixed>}|null
     */
    private function completeHeaders(int $streamId, string $block, bool $endStream): ?array
    {
        $pseudoDone = false;
        $pseudo     = [];
        $headers    = [];

        foreach ($this->decoder->decode($block) as [$name, $value]) {
            if ($name === '') {
                throw Http2Exception::protocol('头名不能为空', $streamId);
            }

            if ($name[0] === ':') {
                if ($pseudoDone) {
                    throw Http2Exception::protocol('伪头必须位于普通头之前', $streamId);
                }
                if (isset($pseudo[$name])) {
                    throw Http2Exception::protocol('重复伪头 ' . $name, $streamId);
                }
                $pseudo[$name] = $value;
                continue;
            }

            $pseudoDone = true;

            // HTTP/2 不允许逐跳头与大写头名（RFC 7540 §8.1.2、§8.1.2.2）
            if ($name !== strtolower($name)) {
                throw Http2Exception::protocol('头名必须小写：' . $name, $streamId);
            }
            if ($name === 'connection' || $name === 'keep-alive' || $name === 'proxy-connection'
                || $name === 'transfer-encoding' || $name === 'upgrade') {
                throw Http2Exception::protocol('禁止的逐跳头：' . $name, $streamId);
            }

            // 同名头合并：cookie 用 "; " 拼接，其余用 ", "（RFC 7540 §8.1.2.5）
            if (isset($headers[$name])) {
                $headers[$name] .= ($name === 'cookie' ? '; ' : ', ') . $value;
            } else {
                $headers[$name] = $value;
            }
        }

        if (!isset($pseudo[':method'], $pseudo[':path'], $pseudo[':scheme'])) {
            throw Http2Exception::protocol('缺少必需伪头', $streamId);
        }
        if ($pseudo[':path'] === '') {
            throw Http2Exception::protocol(':path 不能为空', $streamId);
        }

        $stream            = &$this->streams[$streamId];
        $stream['pseudo']  = $pseudo;
        $stream['headers'] = $headers;

        if (!$endStream) {
            return null;
        }

        $stream['state'] = self::STATE_HALF_CLOSED_REMOTE;

        return ['stream' => $streamId, 'request' => $this->buildRequest($streamId)];
    }

    // ----------------------------------------------------------------- DATA

    /**
     * @param array{type: int, flags: int, stream: int, payload: string, size: int} $frame
     * @return array{stream: int, request: array<string, mixed>}|null
     */
    private function onData(array $frame): ?array
    {
        $streamId = $frame['stream'];
        if ($streamId === 0) {
            throw Http2Exception::protocol('DATA 的流 ID 不能为 0');
        }

        // 流控按「原始负载长度」计（含填充），与解析后的可用字节无关
        $consumed = strlen($frame['payload']);
        $this->consumeRecvWindow($streamId, $consumed);

        if (!isset($this->streams[$streamId])) {
            // 已被 RST 或从未存在：窗口照常归还，避免连接级窗口泄漏
            throw Http2Exception::streamClosed('对已关闭的流发送 DATA', $streamId);
        }

        $payload = $frame['payload'];
        if (($frame['flags'] & Frame::FLAG_PADDED) !== 0) {
            $payload = Frame::stripPadding($payload, $streamId);
        }

        $stream          = &$this->streams[$streamId];
        $stream['body'] .= $payload;

        if (($frame['flags'] & Frame::FLAG_END_STREAM) === 0) {
            return null;
        }

        $stream['state'] = self::STATE_HALF_CLOSED_REMOTE;

        return ['stream' => $streamId, 'request' => $this->buildRequest($streamId)];
    }

    /**
     * 扣减双层接收窗口，低于一半时补发 WINDOW_UPDATE 让对端继续发送。
     */
    private function consumeRecvWindow(int $streamId, int $bytes): void
    {
        if ($bytes === 0) {
            return;
        }

        $this->recvWindow -= $bytes;
        if ($this->recvWindow < 0) {
            throw Http2Exception::flowControl('连接级接收窗口被突破');
        }
        if ($this->recvWindow < $this->initialWindowSize >> 1) {
            $delta             = $this->initialWindowSize - $this->recvWindow;
            $this->recvWindow += $delta;
            $this->outBuffer  .= Frame::windowUpdate(0, $delta);
        }

        if (!isset($this->streams[$streamId])) {
            return;
        }

        $stream               = &$this->streams[$streamId];
        $stream['recvWindow'] -= $bytes;
        if ($stream['recvWindow'] < 0) {
            throw Http2Exception::flowControl('流级接收窗口被突破', $streamId);
        }
        if ($stream['recvWindow'] < $this->initialWindowSize >> 1) {
            $delta                = $this->initialWindowSize - $stream['recvWindow'];
            $stream['recvWindow'] += $delta;
            $this->outBuffer     .= Frame::windowUpdate($streamId, $delta);
        }
    }

    /**
     * 把流上下文组装为与 HTTP/1.1 完全一致的请求数组，业务代码无需区分协议版本。
     *
     * @return array<string, mixed>
     */
    private function buildRequest(int $streamId): array
    {
        $stream  = $this->streams[$streamId];
        $pseudo  = $stream['pseudo'];
        $headers = $stream['headers'];
        $uri     = $pseudo[':path'];
        $body    = $stream['body'];

        // :authority 映射为 Host，让依赖 Host 的业务代码原样工作
        if (isset($pseudo[':authority']) && $pseudo[':authority'] !== '' && !isset($headers['host'])) {
            $headers['host'] = $pseudo[':authority'];
        }

        $query    = [];
        $queryPos = strpos($uri, '?');
        if ($queryPos !== false) {
            $path = substr($uri, 0, $queryPos);
            parse_str(substr($uri, $queryPos + 1), $query);
        } else {
            $path = $uri;
        }
        if ($path === '') {
            $path = '/';
        }

        return [
            'method'   => $pseudo[':method'],
            'uri'      => $uri,
            'path'     => $path,
            'query'    => $query,
            'protocol' => 'HTTP/2',
            'headers'  => $headers,
            'body'     => $body,
            'get'      => $query,
            'post'     => self::parseBody($body, $headers['content-type'] ?? ''),
            'scheme'   => $pseudo[':scheme'] ?? 'http',
            'stream'   => $streamId,
        ];
    }

    /** @return array<string, mixed> */
    private static function parseBody(string $body, string $contentType): array
    {
        if ($body === '' || $contentType === '') {
            return [];
        }
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $post);
            return $post;
        }
        if (str_contains($contentType, 'application/json') && json_validate($body)) {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    // ------------------------------------------------------------ 连接级帧

    /**
     * @param array{type: int, flags: int, stream: int, payload: string, size: int} $frame
     */
    private function onSettings(array $frame): null
    {
        if ($frame['stream'] !== 0) {
            throw Http2Exception::protocol('SETTINGS 必须在流 0 上');
        }
        if (($frame['flags'] & Frame::FLAG_ACK) !== 0) {
            if ($frame['payload'] !== '') {
                throw Http2Exception::frameSize('SETTINGS ACK 必须为空负载');
            }
            return null;
        }

        $this->applySettings($frame['payload']);

        $this->outBuffer .= Frame::settingsAck();

        return null;
    }

    /**
     * 解析并应用对端 SETTINGS 负载（不产生 ACK）。
     *
     * 抽出来是为了让 h2c 升级复用：`HTTP2-Settings` 请求头携带的设置由 101
     * 响应隐式确认，按 RFC 7540 §3.2.1 不得再发 SETTINGS ACK。
     */
    private function applySettings(string $payload): void
    {
        foreach (Frame::decodeSettings($payload) as $id => $value) {
            switch ($id) {
                case Frame::SETTINGS_INITIAL_WINDOW_SIZE:
                    if ($value > Frame::MAX_WINDOW_SIZE) {
                        throw Http2Exception::flowControl('SETTINGS_INITIAL_WINDOW_SIZE 越界');
                    }
                    // 变更需按差值同步调整所有已存在流的发送窗口（RFC 7540 §6.9.2）
                    $delta                       = $value - $this->peerInitialWindowSize;
                    $this->peerInitialWindowSize = $value;
                    if ($delta !== 0) {
                        foreach ($this->streams as $id2 => $_) {
                            $this->streams[$id2]['sendWindow'] += $delta;
                        }
                    }
                    break;

                case Frame::SETTINGS_MAX_FRAME_SIZE:
                    if ($value < Frame::MIN_MAX_FRAME_SIZE || $value > Frame::MAX_MAX_FRAME_SIZE) {
                        throw Http2Exception::protocol('SETTINGS_MAX_FRAME_SIZE 越界');
                    }
                    $this->peerMaxFrameSize = $value;
                    break;

                case Frame::SETTINGS_HEADER_TABLE_SIZE:
                    $this->encoder->setMaxDynamicSize($value);
                    break;

                case Frame::SETTINGS_ENABLE_PUSH:
                    if ($value !== 0 && $value !== 1) {
                        throw Http2Exception::protocol('SETTINGS_ENABLE_PUSH 只能为 0 或 1');
                    }
                    break;

                default:
                    // 未知设置项必须忽略（RFC 7540 §6.5.2）
                    break;
            }
        }
    }

    /**
     * @param array{type: int, flags: int, stream: int, payload: string, size: int} $frame
     */
    private function onWindowUpdate(array $frame): null
    {
        if (strlen($frame['payload']) !== 4) {
            throw Http2Exception::frameSize('WINDOW_UPDATE 负载必须为 4 字节');
        }

        $increment = unpack('N', $frame['payload'])[1] & 0x7FFFFFFF;
        if ($increment === 0) {
            throw Http2Exception::protocol('WINDOW_UPDATE 增量不能为 0', $frame['stream']);
        }

        if ($frame['stream'] === 0) {
            $this->sendWindow += $increment;
            if ($this->sendWindow > Frame::MAX_WINDOW_SIZE) {
                throw Http2Exception::flowControl('连接级发送窗口溢出');
            }
            $this->flushPending();
            return null;
        }

        if (!isset($this->streams[$frame['stream']])) {
            return null; // 流已关闭，忽略
        }

        $this->streams[$frame['stream']]['sendWindow'] += $increment;
        if ($this->streams[$frame['stream']]['sendWindow'] > Frame::MAX_WINDOW_SIZE) {
            throw Http2Exception::flowControl('流级发送窗口溢出', $frame['stream']);
        }
        $this->flushPending();

        return null;
    }

    /**
     * @param array{type: int, flags: int, stream: int, payload: string, size: int} $frame
     */
    private function onPing(array $frame): null
    {
        if ($frame['stream'] !== 0) {
            throw Http2Exception::protocol('PING 必须在流 0 上');
        }
        if (strlen($frame['payload']) !== 8) {
            throw Http2Exception::frameSize('PING 负载必须为 8 字节');
        }
        if (($frame['flags'] & Frame::FLAG_ACK) === 0) {
            $this->outBuffer .= Frame::pingAck($frame['payload']);
        }

        return null;
    }

    /**
     * @param array{type: int, flags: int, stream: int, payload: string, size: int} $frame
     */
    private function onRstStream(array $frame): null
    {
        if ($frame['stream'] === 0) {
            throw Http2Exception::protocol('RST_STREAM 的流 ID 不能为 0');
        }
        if (strlen($frame['payload']) !== 4) {
            throw Http2Exception::frameSize('RST_STREAM 负载必须为 4 字节');
        }
        unset($this->streams[$frame['stream']]);

        return null;
    }

    private function onGoaway(): null
    {
        $this->closed = true;

        return null;
    }

    /**
     * @param array{type: int, flags: int, stream: int, payload: string, size: int} $frame
     */
    private function onPriority(array $frame): null
    {
        if (strlen($frame['payload']) !== 5) {
            throw Http2Exception::frameSize('PRIORITY 负载必须为 5 字节', $frame['stream']);
        }
        // 本实现不做优先级调度：单 worker 内按到达顺序处理已足够，
        // 强行实现依赖树反而引入排队抖动。

        return null;
    }

    // -------------------------------------------------------------- 响应

    /**
     * 发送一个完整响应（头 + 体 + END_STREAM）。
     *
     * @param array<mixed, mixed> $headers 普通响应头，名字大小写不敏感；
     *                                     同名多值见 {@see normalizeHeaders()}
     */
    public function respond(int $streamId, int $status, array $headers, string $body): bool
    {
        if (!isset($this->streams[$streamId])) {
            return false;
        }

        $this->writeHeaders($streamId, $status, $headers, $body === '');

        if ($body !== '') {
            $this->streams[$streamId]['pending']         = $body;
            $this->streams[$streamId]['endAfterPending'] = true;
            $this->flushPending();
        }

        return true;
    }

    /**
     * 只发响应头，随后由 {@see writeData()} 分次推送流式内容。
     *
     * @param array<mixed, mixed> $headers 同 {@see respond()}
     */
    public function respondHeaders(int $streamId, int $status, array $headers): bool
    {
        if (!isset($this->streams[$streamId]) || $this->streams[$streamId]['headersSent']) {
            return false;
        }
        $this->writeHeaders($streamId, $status, $headers, false);

        return true;
    }

    /**
     * 追加一段响应体。$end 为 true 时带上 END_STREAM 并关闭流。
     */
    public function writeData(int $streamId, string $data, bool $end = false): bool
    {
        if (!isset($this->streams[$streamId])) {
            return false;
        }
        if (!$this->streams[$streamId]['headersSent']) {
            $this->writeHeaders($streamId, 200, [], false);
        }

        $this->streams[$streamId]['pending'] .= $data;
        if ($end) {
            $this->streams[$streamId]['endAfterPending'] = true;
        }
        $this->flushPending();

        return true;
    }

    /**
     * 把三种书写形式的响应头统一成 HPACK 需要的有序 [name, value] 列表。
     *
     * HTTP 响应头允许重复（`Set-Cookie` 是刚需），单纯的 `array<string, string>`
     * 表达不了，因此这里额外接受「值为数组」与「列表对」两种写法：
     *
     * ```php
     * ['content-type' => 'text/plain']                  // 单值
     * ['set-cookie'   => ['sid=a', 'csrf=b']]           // 同名多值
     * [['set-cookie', 'sid=a'], ['set-cookie', 'csrf=b']] // 显式有序对
     * ```
     *
     * @param array<mixed, mixed> $headers
     * @return list<array{0: string, 1: string}>
     */
    public static function normalizeHeaders(array $headers): array
    {
        $pairs = [];

        foreach ($headers as $name => $value) {
            // 列表对写法：[[name, value], ...]
            if (is_int($name) && is_array($value) && array_key_exists(0, $value) && array_key_exists(1, $value)) {
                $pairs[] = [strtolower((string) $value[0]), (string) $value[1]];
                continue;
            }

            // 同名多值：['set-cookie' => ['a', 'b']]
            if (is_array($value)) {
                $lower = strtolower((string) $name);
                foreach ($value as $item) {
                    $pairs[] = [$lower, (string) $item];
                }
                continue;
            }

            $pairs[] = [strtolower((string) $name), (string) $value];
        }

        return $pairs;
    }

    /**
     * @param array<mixed, mixed> $headers
     */
    private function writeHeaders(int $streamId, int $status, array $headers, bool $endStream): void
    {
        $list = [[':status', (string) $status]];

        foreach (self::normalizeHeaders($headers) as [$lower, $value]) {
            // HTTP/2 禁止逐跳头，静默丢弃比让对端断连更友好
            if ($lower === 'connection' || $lower === 'keep-alive' || $lower === 'transfer-encoding'
                || $lower === 'upgrade' || $lower === 'proxy-connection') {
                continue;
            }
            $list[] = [$lower, $value];
        }

        $block = $this->encoder->encode($list);
        $flags = Frame::FLAG_END_HEADERS | ($endStream ? Frame::FLAG_END_STREAM : 0);

        // 头块超过对端 MAX_FRAME_SIZE 时拆成 HEADERS + CONTINUATION 序列
        if (strlen($block) <= $this->peerMaxFrameSize) {
            $this->outBuffer .= Frame::encode(Frame::TYPE_HEADERS, $flags, $streamId, $block);
        } else {
            $first            = substr($block, 0, $this->peerMaxFrameSize);
            $rest             = substr($block, $this->peerMaxFrameSize);
            $this->outBuffer .= Frame::encode(
                Frame::TYPE_HEADERS,
                $endStream ? Frame::FLAG_END_STREAM : 0,
                $streamId,
                $first
            );
            while ($rest !== '') {
                $piece            = substr($rest, 0, $this->peerMaxFrameSize);
                $rest             = substr($rest, strlen($piece));
                $this->outBuffer .= Frame::encode(
                    Frame::TYPE_CONTINUATION,
                    $rest === '' ? Frame::FLAG_END_HEADERS : 0,
                    $streamId,
                    $piece
                );
            }
        }

        $this->streams[$streamId]['headersSent'] = true;

        if ($endStream) {
            $this->closeStream($streamId);
        }
    }

    /**
     * 在双层窗口允许的范围内把各流的待发数据切成 DATA 帧写出。
     */
    private function flushPending(): void
    {
        foreach ($this->streams as $streamId => $stream) {
            if ($stream['pending'] === '' && !$stream['endAfterPending']) {
                continue;
            }

            $endStreamSent = false;

            while ($this->streams[$streamId]['pending'] !== '') {
                $allowed = min(
                    $this->sendWindow,
                    $this->streams[$streamId]['sendWindow'],
                    $this->peerMaxFrameSize,
                    strlen($this->streams[$streamId]['pending'])
                );
                if ($allowed <= 0) {
                    break; // 窗口耗尽，等待对端 WINDOW_UPDATE
                }

                $piece                                   = substr($this->streams[$streamId]['pending'], 0, $allowed);
                $this->streams[$streamId]['pending']     = substr($this->streams[$streamId]['pending'], $allowed);
                $this->sendWindow                       -= $allowed;
                $this->streams[$streamId]['sendWindow'] -= $allowed;

                $last = $this->streams[$streamId]['pending'] === ''
                    && $this->streams[$streamId]['endAfterPending'];

                $this->outBuffer .= Frame::encode(
                    Frame::TYPE_DATA,
                    $last ? Frame::FLAG_END_STREAM : 0,
                    $streamId,
                    $piece
                );

                $endStreamSent = $endStreamSent || $last;
            }

            if ($this->streams[$streamId]['pending'] === '' && $this->streams[$streamId]['endAfterPending']) {
                // 待发数据已排空（或本就为空，例如 endChunk() 收尾时），此时若还没有
                // 任何一帧携带过 END_STREAM，必须补一个空 DATA 帧把流关掉——
                // 少了它客户端会一直等响应结束，表现为请求挂起。
                if (!$endStreamSent) {
                    $this->outBuffer .= Frame::encode(Frame::TYPE_DATA, Frame::FLAG_END_STREAM, $streamId, '');
                }
                $this->closeStream($streamId);
            }
        }
    }

    private function closeStream(int $streamId): void
    {
        unset($this->streams[$streamId]);
    }

    /** 主动重置一条流 */
    public function resetStream(int $streamId, int $errorCode = Frame::ERROR_CANCEL): void
    {
        if (isset($this->streams[$streamId])) {
            unset($this->streams[$streamId]);
            $this->outBuffer .= Frame::rstStream($streamId, $errorCode);
        }
    }

    /** 发送 GOAWAY，之后连接应在缓冲写净后关闭 */
    public function goaway(int $errorCode = Frame::ERROR_NO_ERROR, string $debug = ''): void
    {
        if ($this->goawaySent) {
            return;
        }
        $this->goawaySent = true;
        $this->outBuffer .= Frame::goaway($this->lastStreamId, $errorCode, $debug);
        $this->closed     = true;
    }

    /** 取走并清空待发送字节 */
    public function drain(): string
    {
        $out             = $this->outBuffer;
        $this->outBuffer = '';

        return $out;
    }

    public function hasPendingOutput(): bool
    {
        return $this->outBuffer !== '';
    }

    /** @return array<string, int|bool> 诊断快照 */
    public function stats(): array
    {
        return [
            'streams'        => count($this->streams),
            'last_stream'    => $this->lastStreamId,
            'send_window'    => $this->sendWindow,
            'recv_window'    => $this->recvWindow,
            'peer_max_frame' => $this->peerMaxFrameSize,
            'closed'         => $this->closed,
        ];
    }
}
