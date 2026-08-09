<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Signal\SignalDispatcher;
use Kode\Process\Signal\SignalHandler;
use PHPUnit\Framework\TestCase;

final class SignalHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        // 还原引擎级异步信号状态，避免污染其它测试
        if (function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(false);
        }
        SignalHandler::resetInstance();
    }

    public function testSetAsyncDispatchTogglesEngineAsync(): void
    {
        $handler = SignalHandler::getInstance();

        $handler->setAsyncDispatch(true);
        $this->assertTrue(\pcntl_async_signals(), 'setAsyncDispatch(true) 应启用引擎级异步信号');

        $handler->setAsyncDispatch(false);
        $this->assertFalse(\pcntl_async_signals(), 'setAsyncDispatch(false) 应关闭引擎级异步信号');
    }

    public function testAsyncQueueDeduplicatesSameSignal(): void
    {
        $handler = SignalHandler::getInstance();
        $handler->setAsyncDispatch(true);

        $count = 0;
        $handler->register(SIGUSR2, static function () use (&$count): void {
            $count++;
        });

        // 信号风暴：同一信号连续到达 5 次
        for ($i = 0; $i < 5; $i++) {
            $handler->handleSignal(SIGUSR2);
        }

        $handler->processQueue();

        $this->assertSame(1, $count, '同一信号在异步队列中应去重为一次分发');
        $handler->setAsyncDispatch(false);
    }

    public function testQueueHasUpperBound(): void
    {
        $handler = SignalHandler::getInstance();
        $handler->setAsyncDispatch(true);

        $handler->register(SIGUSR1, static function (): void {});

        // 超过上限的异类信号不应无限增长
        for ($i = 0; $i < 300; $i++) {
            $handler->handleSignal($i < 256 ? SIGUSR1 : SIGUSR2);
        }

        $ref = new \ReflectionProperty(SignalHandler::class, 'signalQueue');
        $ref->setAccessible(true);
        $queue = $ref->getValue($handler);

        $this->assertLessThanOrEqual(256, count($queue), '队列不应超过上限');
        $handler->setAsyncDispatch(false);
    }

    public function testDispatcherOffRemovesListenerByIdentity(): void
    {
        $dispatcher = new SignalDispatcher();
        $fired = 0;
        $cb = static function () use (&$fired): void {
            $fired++;
        };

        $dispatcher->on(SIGUSR1, $cb);
        $dispatcher->getHandler()->dispatch(SIGUSR1);
        $this->assertSame(1, $fired);

        // 按可调用对象身份移除
        $dispatcher->off(SIGUSR1, $cb);
        $dispatcher->getHandler()->dispatch(SIGUSR1);
        $this->assertSame(1, $fired, 'off() 应按身份移除监听器，而非泄漏');
    }

    public function testDispatcherOffRemovesListenerByEventId(): void
    {
        $dispatcher = new SignalDispatcher();
        $fired = 0;
        $cb = static function () use (&$fired): void {
            $fired++;
        };

        $id = $dispatcher->on(SIGUSR2, $cb);
        $this->assertIsString($id);

        $dispatcher->off(SIGUSR2, $id);
        $this->assertFalse($dispatcher->hasListeners(SIGUSR2));
    }
}
