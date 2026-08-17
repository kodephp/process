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

    /** 本端接受的最大「未压缩」头列表字节数（SETTINGS_MAX_HEADER_LIST_SIZE），防御超大头 */
    private int $maxHeaderListSize;

    /** 单条流允许的最大请求体字节数（防御请求体无限缓冲耗尽内存），见 DEFAULT_MAX_REQUEST_BODY */
    private int $maxRequestBodySize;

    /** 流状态对象池：关闭的流回收于此，新建时复用，省去哈希桶反复分配 */
    private array $streamPool = [];

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
     * 拼接中的这个头块所属的流是否已被拒绝（并发上限）。
     *
     * 被拒的流也必须把头块**解完**才能维持 HPACK 上下文，因此拒绝决定要一路带到
     * 头块收齐之时才执行 RST_STREAM。同一时刻只可能有一个头块在拼接，故单个标志足够。
     */
    private bool $continuationRefused = false;

    /**
     * 单条头块序列允许的最大压缩字节数（CONTINUATION 洪泛防护，CVE-2024-27316 同类）。
     * 超过即视为攻击：RST_STREAM(PROTOCOL_ERROR) 并丢弃，避免 continuationBuffer 无限增长。
     */
    private const int MAX_HEADER_BLOCK_SIZE = 65536;

    /** 单条头块序列允许的最大 CONTINUATION 帧数（同上，防止帧数洪泛） */
    private const int MAX_CONTINUATION_FRAMES = 16;

    /** 本端接受的最大「未压缩」头列表字节数（SETTINGS_MAX_HEADER_LIST_SIZE 默认值） */
    public const int DEFAULT_MAX_HEADER_LIST_SIZE = 65536;

    /**
     * 单条流允许的最大**请求体**字节数（默认 10MB，与 HTTP/1.1 {@see \Kode\Process\Protocol\HttpProtocol::MAX_LENGTH}
     * 对称的整请求上限保持一致）。
     *
     * HTTP/2 接收窗口随字节到达而补发 WINDOW_UPDATE（见 {@see consumeRecvWindow}），对端因此可持续灌入
     * 请求体；若不设上限，`$stream['body']` 会被无限累积，单连接即可耗尽内存。此处与 HTTP/1.1 一样
     * 给出硬上限，超限即 RST_STREAM(ENHANCE_YOUR_CALM) 并丢弃该流（不影响连接与其他流）。
     */
    public const int DEFAULT_MAX_REQUEST_BODY = 10485760;

    /** 流状态对象池上限：超过即不再回收，避免长连接下池无限增长 */
    private const int STREAM_POOL_LIMIT = 64;

    /**
     * RST_STREAM 洪泛预算（HTTP/2 Rapid Reset，CVE-2023-44487）。
     *
     * 攻击手法：客户端不断「HEADERS → 立刻 RST_STREAM」。因为流被立即销毁，
     * 并发数永远不触及 SETTINGS_MAX_CONCURRENT_STREAMS，却已迫使服务端完成
     * HPACK 解码、分配流、派发请求——单连接即可打满 CPU。
     *
     * 防护采用「预算 + 抵扣」而非时间窗口：收到对端 RST_STREAM 预算 +1，
     * 每有一条流**正常完成响应**（{@see closeStream}）预算 -1（不低于 0）。
     * 正常客户端偶尔取消请求时完成数远多于取消数，预算稳定在 0 附近；
     * 而 Rapid Reset 只重置不完成，预算线性累积，很快触顶。
     * 这样无需依赖时钟，行为确定、可测试，也不会误伤正常取消。
     */
    private int $resetStreamBudget = 0;

    /** 预算上限 = maxConcurrentStreams × 此系数，触顶即 GOAWAY(ENHANCE_YOUR_CALM) */
    private const int RESET_STREAM_BUDGET_FACTOR = 4;

    /**
     * 两次 {@see drain} 之间因对端控制帧而排队的 ACK 数（PING / SETTINGS 洪泛防护）。
     *
     * 对端可以只发不读，疯狂灌 PING / SETTINGS 迫使本端无限堆积 ACK 到 outBuffer，
     * 造成内存放大。drain() 表示已把缓冲交给传输层，计数随之归零；若在一次
     * drain 之间就堆到上限，说明对端明显异常。
     */
    private int $queuedControlFrames = 0;

    private const int MAX_QUEUED_CONTROL_FRAMES = 1000;

    /**
     * 响应头块缓存：键为 `serialize([status, headers])`，值为已 HPACK 编码的整块。
     *
     * 服务端编码器严格走「不索引 / 从不索引」表示（见 {@see Hpack::encode()}），
     * 动态表恒为空，因此同一 `(status, headers)` 编码出的字节块是确定且可复现的——
     * 这与 {@see Hpack::$literalCache} 同源：缓存纯函数结果，线格式完全一致。
     * 真实服务的响应头组合高度固定（同一 status + 同一组头反复出现），稳态下
     * 除首个请求外几乎全部命中，直接跳过 normalizeHeaders + HPACK encode 两项开销。
     * 上限 {@see RESPONSE_BLOCK_CACHE_LIMIT} 防止动态值（如每次不同的 Date）无限膨胀。
     *
     * @var array<string, string>
     */
    private static array $responseBlockCache = [];

    /** 已缓存条目数（达到上限后停止写入，避免抖动与内存增长） */
    private static int $responseBlockCacheCount = 0;

    private const int RESPONSE_BLOCK_CACHE_LIMIT = 256;

    /**
     * 「还有字节没发完」的流索引，供 {@see flushPending()} 定向遍历。
     *
     * WINDOW_UPDATE 在大响应场景下极其密集，每收到一个就把全部活跃流扫一遍的话，
     * 代价随并发流数线性上涨（实测 128 流时每次 2.5µs，且绝大多数流无事可做）。
     * 索引让空闲连接上的 flushPending 退化成一次空循环。
     *
     * 不变式：`$streamId ∈ pendingStreams` ⟺ 该流 `pending !== ''`
     * 或仍欠一个 END_STREAM。写入点只有 {@see respond()} / {@see writeData()}，
     * 摘除点只有 {@see flushPending()} 收尾与 {@see freeStream()}。
     *
     * @var array<int, true>
     */
    private array $pendingStreams = [];

    private int $lastStreamId = 0;

    private int $highestClientStream = 0;

    private readonly Hpack $decoder;

    private readonly Hpack $encoder;

    /**
     * @param int $maxConcurrentStreams 本端允许的并发流上限
     * @param int $initialWindowSize    本端通告的初始接收窗口
     * @param int $maxFrameSize         本端可接收的最大帧长
     * @param int $maxRequestBodySize   单条流允许的最大请求体字节数（0 表示不限制，仅用于测试）
     */
    public function __construct(
        int $maxConcurrentStreams = 128,
        int $initialWindowSize = 1048576,
        int $maxFrameSize = Frame::MIN_MAX_FRAME_SIZE,
        int $headerTableSize = 4096,
        int $maxHeaderListSize = self::DEFAULT_MAX_HEADER_LIST_SIZE,
        int $maxRequestBodySize = self::DEFAULT_MAX_REQUEST_BODY,
    ) {
        $this->maxConcurrentStreams = max(1, $maxConcurrentStreams);
        $this->initialWindowSize    = min(max(Frame::DEFAULT_WINDOW_SIZE, $initialWindowSize), Frame::MAX_WINDOW_SIZE);
        $this->maxFrameSize         = min(max(Frame::MIN_MAX_FRAME_SIZE, $maxFrameSize), Frame::MAX_MAX_FRAME_SIZE);
        $this->maxHeaderListSize    = max(0, $maxHeaderListSize);
        $this->maxRequestBodySize   = max(0, $maxRequestBodySize);
        $this->recvWindow           = $this->initialWindowSize;
        $this->decoder              = new Hpack($headerTableSize);
        $this->encoder              = new Hpack($headerTableSize);
    }

    /**
     * 清空响应头块缓存（主要用于测试隔离与基准冷启动）。
     * 不影响任何已建立的会话，仅丢弃可重新计算的缓存。
     */
    public static function clearResponseBlockCache(): void
    {
        self::$responseBlockCache        = [];
        self::$responseBlockCacheCount   = 0;
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
            Frame::SETTINGS_MAX_HEADER_LIST_SIZE   => $this->maxHeaderListSize,
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

        $stream = $this->acquireStream();
        $stream['state']   = self::STATE_HALF_CLOSED_REMOTE;
        $stream['headers'] = $headers;
        $stream['pseudo']  = [
            ':method'    => (string)($request['method'] ?? 'GET'),
            ':path'      => (string)($request['uri'] ?? '/'),
            ':scheme'    => 'http',
            ':authority' => $headers['host'] ?? '',
        ];
        $stream['body']   = (string)($request['body'] ?? '');
        $this->streams[1] = $stream;

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
        // 并发流上限：拒绝该流，但**绝不能在此直接 return**。
        // HPACK 是连接级有状态编码：跳过这一个头块的解码，本端解码器动态表就会与对端
        // 的编码器表永久失步，之后每一个头块都会解错（表现为随机的头名/头值错乱，
        // 甚至把 :path 解成别的值）。正确做法是照常解完以维持上下文，解完后再丢弃这批
        // 头并回 RST_STREAM(REFUSED_STREAM)——见 completeHeaders() 的 $refused 分支。
        $refused = count($this->streams) >= $this->maxConcurrentStreams;

        $this->highestClientStream = $streamId;
        $this->lastStreamId        = $streamId;

        // 被拒的流不建流对象，才不会占用并发槽位
        if (!$refused) {
            $this->streams[$streamId] = $this->acquireStream();
        }

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
            $this->continuationRefused   = $refused;
            return null;
        }

        return $this->completeHeaders($streamId, $payload, $endStream, $refused);
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
        $refused   = $this->continuationRefused;

        $this->continuationStream    = 0;
        $this->continuationBuffer    = '';
        $this->continuationEndStream = false;
        $this->continuationFrames    = 0;
        $this->continuationRefused   = false;

        return $this->completeHeaders($streamId, $block, $endStream, $refused);
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
        $this->continuationRefused  = false;
        $this->freeStream($streamId);
    }

    /**
     * 头块收齐：HPACK 解码 → 校验伪头 → 若同时 END_STREAM 则请求已完整。
     *
     * @param bool $refused 该流已被并发上限拒绝；仍需解码以维持 HPACK 上下文，解完即 RST
     * @return array{stream: int, request: array<string, mixed>}|null
     */
    private function completeHeaders(int $streamId, string $block, bool $endStream, bool $refused = false): ?array
    {
        $pseudoDone = false;
        $pseudo     = [];
        $headers    = [];
        $listSize   = 0;

        // 解压炸弹防护：上限在 HPACK 解码循环内逐条累计，超限即停止累积输出，
        // 内存不会随「索引引用展开」爆炸（见 Hpack::decode）。头块仍会解完以维持
        // 连接级 HPACK 上下文，因此这里只需按流级拒绝，连接可继续服务其它流。
        $exceeded = false;
        $decoded  = $this->decoder->decode($block, $this->maxHeaderListSize, $exceeded);

        // 已被拒绝的流：上一行的解码已经把连接级 HPACK 上下文推进到位，现在才可以
        // 安全地丢弃这批头。此处不能用 resetStream()——被拒的流从未建过流对象。
        if ($refused) {
            $this->outBuffer .= Frame::rstStream($streamId, Frame::ERROR_REFUSED_STREAM);
            return null;
        }

        if ($exceeded) {
            $this->resetStream($streamId, Frame::ERROR_PROTOCOL);
            return null;
        }

        foreach ($decoded as [$name, $value]) {
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
                // 未压缩头列表体积（RFC 7540 §6.5.2：name+value+32 固定开销），边解码边累计
                $listSize += strlen($name) + strlen($value) + 32;
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

            // 同名头合并：cookie 用 "; " 拼接，其余用 ", "（RFC 7540 §8.1.2.5）。
            // 合并时「头字段固定开销」已在首次计入，这里只追加合并部分的字节。
            if (isset($headers[$name])) {
                $appended     = ($name === 'cookie' ? '; ' : ', ') . $value;
                $headers[$name] .= $appended;
                $listSize     += strlen($appended);
            } else {
                $headers[$name] = $value;
                $listSize      += strlen($name) + strlen($value) + 32;
            }
        }

        // SETTINGS_MAX_HEADER_LIST_SIZE 防御：未压缩头列表超过本端通告上限即拒。
        // 与 CONTINUATION 洪泛防护互补——后者限「压缩后」体积，这里限「解压后」体积，
        // 防止一条低冗余却被解压成巨大的头列表耗尽内存（RFC 7540 §6.5.2）。
        // listSize 已在解码循环中累计，无需二次遍历。
        if ($this->maxHeaderListSize > 0 && $listSize > $this->maxHeaderListSize) {
            $this->resetStream($streamId, Frame::ERROR_PROTOCOL);
            return null;
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

        // 流状态机校验（RFC 7540 §5.1）：half-closed(remote) 意味着对端已经发过
        // END_STREAM，此后该方向不得再有 DATA。不校验的话，对已 END_STREAM 的流继续发
        // DATA 会把负载继续追加进 body，且第二个 END_STREAM 会让**同一条请求被二次派发**
        // 给业务 handler（重复下单 / 重复扣款）。h2c 升级出来的流 1 一开始就是该状态。
        if ($this->streams[$streamId]['state'] !== self::STATE_OPEN) {
            throw Http2Exception::streamClosed('对半关闭的流发送 DATA', $streamId);
        }

        $payload = $frame['payload'];
        if (($frame['flags'] & Frame::FLAG_PADDED) !== 0) {
            $payload = Frame::stripPadding($payload, $streamId);
        }

        $stream          = &$this->streams[$streamId];
        $stream['body'] .= $payload;

        // 请求体体积上限（与 HTTP/1.1 MAX_LENGTH 对称）：接收窗口随字节到达而补发，
        // 对端可持续灌入，必须在此截断，否则 $stream['body'] 无限增长耗尽内存。
        if ($this->maxRequestBodySize > 0 && strlen($stream['body']) > $this->maxRequestBodySize) {
            $this->resetStream($streamId, Frame::ERROR_ENHANCE_YOUR_CALM);
            return null;
        }

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
        $this->countControlFrame();

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

        $p         = $frame['payload'];
        $increment = ((ord($p[0]) << 24)
            | (ord($p[1]) << 16)
            | (ord($p[2]) << 8)
            | ord($p[3])) & 0x7FFFFFFF;
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
            $this->countControlFrame();
        }

        return null;
    }

    /**
     * 记一次因对端控制帧而排队的 ACK；超过上限视为洪泛（见 $queuedControlFrames）。
     */
    private function countControlFrame(): void
    {
        $this->queuedControlFrames++;
        if ($this->queuedControlFrames > self::MAX_QUEUED_CONTROL_FRAMES) {
            $this->goaway(Frame::ERROR_ENHANCE_YOUR_CALM, '控制帧洪泛');
        }
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
        $this->freeStream($frame['stream']);

        // Rapid Reset 防护：只重置不完成的连接会让预算线性累积（见 $resetStreamBudget）
        $this->resetStreamBudget++;
        if ($this->resetStreamBudget > $this->maxResetStreamBudget()) {
            $this->goaway(Frame::ERROR_ENHANCE_YOUR_CALM, 'RST_STREAM 洪泛');
        }

        return null;
    }

    /** RST_STREAM 洪泛预算上限（随并发上限缩放，至少 100，避免小并发配置误伤） */
    private function maxResetStreamBudget(): int
    {
        return max(100, $this->maxConcurrentStreams * self::RESET_STREAM_BUDGET_FACTOR);
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
            $this->pendingStreams[$streamId]             = true;
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
        $this->pendingStreams[$streamId] = true;
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
        // 响应头块是 (status, headers) 的纯函数（编码器动态表恒空），稳态下同一组合
        // 反复出现：以原始输入为键，首次编码后缓存，其后直接命中，跳过
        // normalizeHeaders + HPACK encode 两项开销（仅付出一次 serialize 取键）。
        $key = serialize([$status, $headers]);
        if (isset(self::$responseBlockCache[$key])) {
            $block = self::$responseBlockCache[$key];
        } else {
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
            if (self::$responseBlockCacheCount < self::RESPONSE_BLOCK_CACHE_LIMIT) {
                self::$responseBlockCache[$key] = $block;
                self::$responseBlockCacheCount++;
            }
        }

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
     *
     * 两处刻意的写法，都是为了让大响应不退化成平方复杂度 / 全表扫描：
     *
     * 1. **游标而非重切**。切帧时若每帧都 `$pending = substr($pending, $n)`，
     *    每次都要复制一整份剩余响应体：1MB 响应按 16KB 切要复制 ≈32MB，
     *    耗时随体积平方增长。这里改为在原串上推进整数 `$offset`，循环内只
     *    复制真正要发出去的那一片，收尾时才按最终位置切一次。
     * 2. **只扫有待发数据的流**。WINDOW_UPDATE 会频繁触发本方法，若每次都
     *    遍历全部活跃流，代价随并发流数线性上涨；`$pendingStreams` 是这些
     *    流的索引，空闲连接下这里就是一次空循环。
     */
    private function flushPending(): void
    {
        // 迭代的是按值取到的快照，循环体内增删 $this->pendingStreams 不影响本次遍历
        foreach ($this->pendingStreams as $streamId => $_) {
            if (!isset($this->streams[$streamId])) {
                unset($this->pendingStreams[$streamId]);
                continue;
            }

            $pending = $this->streams[$streamId]['pending'];
            $total   = strlen($pending);
            $offset  = 0;

            $endStreamSent = false;

            while ($offset < $total) {
                $allowed = min(
                    $this->sendWindow,
                    $this->streams[$streamId]['sendWindow'],
                    $this->peerMaxFrameSize,
                    $total - $offset
                );
                if ($allowed <= 0) {
                    break; // 窗口耗尽，等待对端 WINDOW_UPDATE
                }

                $piece   = substr($pending, $offset, $allowed);
                $offset += $allowed;

                $this->sendWindow                       -= $allowed;
                $this->streams[$streamId]['sendWindow'] -= $allowed;

                $last = $offset >= $total && $this->streams[$streamId]['endAfterPending'];

                $this->outBuffer .= Frame::encode(
                    Frame::TYPE_DATA,
                    $last ? Frame::FLAG_END_STREAM : 0,
                    $streamId,
                    $piece
                );

                $endStreamSent = $endStreamSent || $last;
            }

            // 整轮只回写一次：全部发完置空，发了一部分才切出剩余尾巴
            if ($offset > 0) {
                $this->streams[$streamId]['pending'] = $offset >= $total
                    ? ''
                    : substr($pending, $offset);
            }
            unset($pending);

            if ($this->streams[$streamId]['pending'] === '' && $this->streams[$streamId]['endAfterPending']) {
                // 待发数据已排空（或本就为空，例如 endChunk() 收尾时），此时若还没有
                // 任何一帧携带过 END_STREAM，必须补一个空 DATA 帧把流关掉——
                // 少了它客户端会一直等响应结束，表现为请求挂起。
                if (!$endStreamSent) {
                    $this->outBuffer .= Frame::encode(Frame::TYPE_DATA, Frame::FLAG_END_STREAM, $streamId, '');
                }
                $this->closeStream($streamId); // 内部 freeStream() 会摘掉索引
            }

            // 排空且不需要收尾的流退出索引；窗口耗尽仍有余量的留在索引里等下一次
            if (!isset($this->streams[$streamId]) || $this->streams[$streamId]['pending'] === '') {
                unset($this->pendingStreams[$streamId]);
            }
        }
    }

    /**
     * 取一条可复用的流状态数组（对象池）。
     *
     * 高并发 h2 连接上每条请求都新建一条流；若每次都 new 一个 9 字段的关联数组，
     * 其哈希桶要反复分配 / 回收，给内存分配器与 GC 带来稳定压力。这里在连接内维护
     * 一个小数组池：流关闭时回收、新建时复用。由于所有流数组的键集合恒为那 9 个，
     * 复用可避免哈希表重建。池有上限，长连接下也不会无限增长。
     */
    private function acquireStream(): array
    {
        if ($this->streamPool !== []) {
            $stream = array_pop($this->streamPool);
        } else {
            $stream = [];
        }

        $stream['state']           = self::STATE_OPEN;
        $stream['headers']         = [];
        $stream['pseudo']          = [];
        $stream['body']            = '';
        $stream['sendWindow']      = $this->peerInitialWindowSize;
        $stream['recvWindow']      = $this->initialWindowSize;
        $stream['pending']         = '';
        $stream['endAfterPending'] = false;
        $stream['headersSent']     = false;

        return $stream;
    }

    /**
     * 回收一条已关闭的流（归还对象池），防止哈希桶反复分配。
     */
    private function freeStream(int $streamId): void
    {
        // 流一旦消失就没有「待发」可言，索引在这里统一摘除（RST / GOAWAY 等路径同样收敛于此）
        unset($this->pendingStreams[$streamId]);

        if (isset($this->streams[$streamId])) {
            if (count($this->streamPool) < self::STREAM_POOL_LIMIT) {
                $this->streamPool[] = $this->streams[$streamId];
            }
            unset($this->streams[$streamId]);
        }
    }

    /**
     * 一条流「正常完成响应」后关闭：抵扣一点 RST_STREAM 洪泛预算。
     * 与 {@see freeStream}（纯粹的底层回收）区分开，语义即防护依据。
     */
    private function closeStream(int $streamId): void
    {
        $this->freeStream($streamId);

        if ($this->resetStreamBudget > 0) {
            $this->resetStreamBudget--;
        }
    }

    /** 主动重置一条流 */
    public function resetStream(int $streamId, int $errorCode = Frame::ERROR_CANCEL): void
    {
        if (isset($this->streams[$streamId])) {
            $this->freeStream($streamId);
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

        // 缓冲已交给传输层，控制帧排队计数随之归零（洪泛防护只针对「堆积」）
        $this->queuedControlFrames = 0;

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
            // 被发送窗口卡住、还有字节没发完的流数；持续不降说明对端不回补窗口
            'pending_streams' => count($this->pendingStreams),
            'last_stream'    => $this->lastStreamId,
            'send_window'    => $this->sendWindow,
            'recv_window'    => $this->recvWindow,
            'peer_max_frame' => $this->peerMaxFrameSize,
            'closed'         => $this->closed,
            // 安全水位：持续上涨说明对端在打 Rapid Reset / 控制帧洪泛
            'reset_budget'   => $this->resetStreamBudget,
            'reset_limit'    => $this->maxResetStreamBudget(),
            'queued_control' => $this->queuedControlFrames,
            // 请求体硬上限：与 HTTP/1.1 MAX_LENGTH 对称的资源防护
            'max_request_body' => $this->maxRequestBodySize,
        ];
    }
}
