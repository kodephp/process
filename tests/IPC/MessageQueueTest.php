<?php

declare(strict_types=1);

namespace Kode\Process\Tests\IPC;

use Kode\Process\Exceptions\IPCException;
use Kode\Process\IPC\MessageQueue;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * MessageQueue 回归测试：毒丸死循环、close() 语义、反序列化与权限位。
 *
 * 注：System V 消息队列是全主机共享的内核对象，用例结束后必须销毁，
 * 否则会残留并占用 msgmni 名额。
 */
#[Group('ipc')]
final class MessageQueueTest extends TestCase
{
    private int $key = 0;

    protected function setUp(): void
    {
        if (!extension_loaded('sysvmsg')) {
            self::markTestSkipped('需要 sysvmsg 扩展');
        }

        $this->key = 0x4D51_0000 + random_int(1, 0xFFFE);
    }

    protected function tearDown(): void
    {
        if ($this->key !== 0 && msg_queue_exists($this->key)) {
            $queue = @msg_get_queue($this->key);
            if ($queue !== false) {
                @msg_remove_queue($queue);
            }
        }
    }

    /**
     * 缺陷复现：旧实现只校验裸消息长度，随后再包一层信封才投递。
     * 信封比裸消息大，于是「裸消息刚好放得下、信封放不下」的消息会被投进队列，
     * 接收端每次 msg_receive 都以 E2BIG 失败且不出队 —— 成为永远取不走的毒丸。
     */
    public function testSendValidatesEnvelopeSizeNotRawMessageSize(): void
    {
        $queue = new MessageQueue($this->key);

        $payload = str_repeat('x', 512);
        $rawSize = strlen(serialize($payload));
        $envelopeSize = strlen(serialize([
            'pid' => posix_getpid(),
            'data' => $payload,
            'time' => microtime(true),
        ]));

        self::assertGreaterThan($rawSize, $envelopeSize, '信封必然比裸消息大');

        // 缓冲区刚好装得下裸消息，但装不下信封
        $queue->setBufferSize($rawSize + 8);

        try {
            $queue->send($payload);
            self::fail('信封超出接收缓冲区时 send() 必须拒绝，而不是投递一颗毒丸');
        } catch (IPCException) {
            // 预期行为
        }

        self::assertSame(0, $queue->getQueueSize(), '被拒绝的消息不得进入队列');

        $queue->destroy();
    }

    /**
     * 缺陷复现：队首存在超出接收缓冲区的报文时，msg_receive 返回 E2BIG 且不出队。
     * 旧实现直接重试，阻塞模式下没有任何休眠，会把 CPU 打满并永远收不到后续消息。
     */
    public function testReceiveDiscardsPoisonMessageAndContinues(): void
    {
        $queue = new MessageQueue($this->key);
        $queue->setBufferSize(256);

        // 绕过 send()，直接把一条超长报文塞进队列，模拟其他版本 / 其他进程投递的毒丸
        $raw = msg_get_queue($this->key, 0600);
        self::assertNotFalse($raw);
        self::assertTrue(msg_send($raw, 1, str_repeat('P', 1024), false, true));

        // 毒丸之后是一条正常消息
        $queue->send('after-poison');
        self::assertSame(2, $queue->getQueueSize());

        // 必须跳过毒丸拿到正常消息，而不是卡死或超时
        self::assertSame('after-poison', $queue->receive(2.0));
        self::assertSame(0, $queue->getQueueSize(), '毒丸必须被丢弃出队');

        $queue->destroy();
    }

    /**
     * 队列被毒丸占据且没有后续消息时，receive() 必须在超时后退出，而不是空转。
     */
    public function testReceiveWithOnlyPoisonMessageTimesOutInsteadOfSpinning(): void
    {
        $queue = new MessageQueue($this->key);
        $queue->setBufferSize(256);

        $raw = msg_get_queue($this->key, 0600);
        self::assertNotFalse($raw);
        self::assertTrue(msg_send($raw, 1, str_repeat('P', 1024), false, true));

        $this->expectException(IPCException::class);

        try {
            $queue->receive(0.2);
        } finally {
            self::assertSame(0, $queue->getQueueSize(), '毒丸必须被丢弃，不能永久堵塞队列');
            $queue->destroy();
        }
    }

    /**
     * 缺陷复现：close()（以及 __destruct 隐式触发的 close()）调用 msg_remove_queue，
     * 会把全主机共享的队列连同其他进程的在途消息一起销毁。
     */
    public function testCloseOnlyDetachesAndKeepsQueueForOtherProcesses(): void
    {
        $producer = new MessageQueue($this->key);
        $consumer = new MessageQueue($this->key);

        $producer->send('in-flight');

        // 一个「进程」退出：只应脱离句柄
        $consumer->close();

        self::assertTrue(msg_queue_exists($this->key), 'close() 不得销毁内核对象');
        self::assertSame(1, $producer->getQueueSize(), '在途消息不得被丢弃');
        self::assertSame('in-flight', $producer->receive(1.0));

        $producer->destroy();
    }

    /**
     * destroy() 才是真正回收内核对象的入口。
     */
    public function testDestroyRemovesQueue(): void
    {
        $queue = new MessageQueue($this->key);
        self::assertTrue(msg_queue_exists($this->key));

        $queue->destroy();

        self::assertFalse(msg_queue_exists($this->key));
        self::assertTrue($queue->isClosed());
    }

    /**
     * IPC 报文只承载数据：反序列化必须禁止实例化任意类。
     */
    public function testUnserializeRejectsObjects(): void
    {
        $queue = new MessageQueue($this->key);

        $queue->send(new \ArrayObject(['a' => 1]));
        $received = $queue->receive(1.0);

        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $received);
        self::assertNotInstanceOf(\ArrayObject::class, $received);

        $queue->destroy();
    }

    /**
     * 普通数组 / 标量负载不受 allowed_classes 限制影响。
     */
    public function testPlainPayloadRoundTrip(): void
    {
        $queue = new MessageQueue($this->key);

        $queue->send(['job' => 'resize', 'args' => [1, 2, 3]]);

        self::assertSame(['job' => 'resize', 'args' => [1, 2, 3]], $queue->receive(1.0));

        $queue->destroy();
    }

    /**
     * 队列不得对同主机其他本地用户可读：权限位必须是 0600。
     */
    public function testQueueIsCreatedWithOwnerOnlyPermissions(): void
    {
        $queue = new MessageQueue($this->key);

        $stats = msg_stat_queue(msg_get_queue($this->key));
        self::assertIsArray($stats);
        self::assertSame(0600, $stats['msg_perm.mode'] & 0777);

        $queue->destroy();
    }
}
