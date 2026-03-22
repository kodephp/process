<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Version;
use Kode\Process\PhpCompat;
use Kode\Process\Async\EventEmitter;
use Kode\Process\Async\Async;
use Kode\Process\Async\Promise;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testVersionFormat(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::get());
    }

    public function testVersionId(): void
    {
        $this->assertGreaterThan(20000, Version::getId());
    }

    public function testVersionComponents(): void
    {
        $this->assertEquals(2, Version::getMajor());
        $this->assertEquals(3, Version::getMinor());
        $this->assertGreaterThanOrEqual(0, Version::getPatch());
    }

    public function testPhpVersion(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\./', Version::getPhpVersion());
        $this->assertGreaterThan(80000, Version::getPhpVersionId());
    }

    public function testFeatureDetection(): void
    {
        $features = Version::getFeatures();

        $this->assertIsArray($features);
        $this->assertArrayHasKey('fiber', $features);
        $this->assertArrayHasKey('readonly', $features);
        $this->assertArrayHasKey('enums', $features);
    }

    public function testVersionComparison(): void
    {
        $this->assertTrue(Version::isEqualTo('2.3.1'));
        $this->assertFalse(Version::isGreaterThan('3.0.0'));
        $this->assertTrue(Version::isGreaterThan('1.0.0'));
    }
}

final class PhpCompatTest extends TestCase
{
    public function testVersionDetection(): void
    {
        $this->assertNotEmpty(PhpCompat::version());
        $this->assertGreaterThan(80000, PhpCompat::versionId());
    }

    public function testPhp81Features(): void
    {
        $this->assertTrue(PhpCompat::hasReadonlyProperties());
        $this->assertTrue(PhpCompat::hasEnums());
        $this->assertTrue(PhpCompat::hasNeverType());
    }

    public function testPhp85Features(): void
    {
        $hasCloneWith = PhpCompat::hasCloneWith();
        $hasPipeOperator = PhpCompat::hasPipeOperator();

        if (PHP_VERSION_ID >= 80500) {
            $this->assertTrue($hasCloneWith);
            $this->assertTrue($hasPipeOperator);
        } else {
            $this->assertFalse($hasCloneWith);
            $this->assertFalse($hasPipeOperator);
        }
    }

    public function testArrayFind(): void
    {
        $array = [1, 2, 3, 4, 5];

        $result = PhpCompat::arrayFind($array, fn($v) => $v > 3);
        $this->assertEquals(4, $result);

        $result = PhpCompat::arrayFind($array, fn($v) => $v > 10);
        $this->assertNull($result);
    }

    public function testArrayFindKey(): void
    {
        $array = ['a' => 1, 'b' => 2, 'c' => 3];

        $result = PhpCompat::arrayFindKey($array, fn($v) => $v === 2);
        $this->assertEquals('b', $result);
    }

    public function testArrayAny(): void
    {
        $array = [1, 2, 3, 4, 5];

        $this->assertTrue(PhpCompat::arrayAny($array, fn($v) => $v > 3));
        $this->assertFalse(PhpCompat::arrayAny($array, fn($v) => $v > 10));
    }

    public function testArrayAll(): void
    {
        $array = [1, 2, 3, 4, 5];

        $this->assertTrue(PhpCompat::arrayAll($array, fn($v) => $v > 0));
        $this->assertFalse(PhpCompat::arrayAll($array, fn($v) => $v > 3));
    }

    public function testPipe(): void
    {
        $result = PhpCompat::pipe(
            '  HELLO WORLD  ',
            'trim',
            'strtolower',
            fn($s) => str_replace(' ', '-', $s)
        );

        $this->assertEquals('hello-world', $result);
    }
}

final class EventEmitterTest extends TestCase
{
    public function testOnAndEmit(): void
    {
        $emitter = new EventEmitter();
        $called = false;

        $emitter->on('test', function () use (&$called) {
            $called = true;
        });

        $emitter->emit('test');

        $this->assertTrue($called);
    }

    public function testOnce(): void
    {
        $emitter = new EventEmitter();
        $count = 0;

        $emitter->once('test', function () use (&$count) {
            $count++;
        });

        $emitter->emit('test');
        $emitter->emit('test');

        $this->assertEquals(1, $count);
    }

    public function testOff(): void
    {
        $emitter = new EventEmitter();
        $called = false;

        $listener = function () use (&$called) {
            $called = true;
        };

        $emitter->on('test', $listener);
        $emitter->off('test', $listener);
        $emitter->emit('test');

        $this->assertFalse($called);
    }

    public function testOffAll(): void
    {
        $emitter = new EventEmitter();
        $count = 0;

        $emitter->on('test', function () use (&$count) {
            $count++;
        });

        $emitter->off('test');
        $emitter->emit('test');

        $this->assertEquals(0, $count);
    }

    public function testHasListeners(): void
    {
        $emitter = new EventEmitter();

        $this->assertFalse($emitter->hasListeners('test'));

        $emitter->on('test', fn() => null);

        $this->assertTrue($emitter->hasListeners('test'));
    }

    public function testListenerCount(): void
    {
        $emitter = new EventEmitter();

        $this->assertEquals(0, $emitter->listenerCount('test'));

        $emitter->on('test', fn() => null);
        $emitter->on('test', fn() => null);

        $this->assertEquals(2, $emitter->listenerCount('test'));
    }

    public function testEventNames(): void
    {
        $emitter = new EventEmitter();

        $emitter->on('event1', fn() => null);
        $emitter->on('event2', fn() => null);

        $names = $emitter->eventNames();

        $this->assertContains('event1', $names);
        $this->assertContains('event2', $names);
    }

    public function testPrependListener(): void
    {
        $emitter = new EventEmitter();
        $order = [];

        $emitter->on('test', function () use (&$order) {
            $order[] = 'second';
        });

        $emitter->prependListener('test', function () use (&$order) {
            $order[] = 'first';
        });

        $emitter->emit('test');

        $this->assertEquals(['first', 'second'], $order);
    }
}

final class PromiseTest extends TestCase
{
    public function testResolve(): void
    {
        $promise = Promise::resolve('value');

        $this->assertTrue($promise->isFulfilled());
        $this->assertEquals('value', $promise->getValue());
    }

    public function testReject(): void
    {
        $promise = Promise::reject('reason');

        $this->assertTrue($promise->isRejected());
        $this->assertEquals('reason', $promise->getReason());
    }

    public function testThen(): void
    {
        $promise = Promise::resolve(1);
        $result = null;

        $promise->then(function ($value) use (&$result) {
            $result = $value * 2;
        });

        Async::runMicrotasks();

        $this->assertEquals(2, $result);
    }

    public function testCatch(): void
    {
        $promise = Promise::reject('error');
        $caught = false;

        $promise->catch(function ($reason) use (&$caught) {
            $caught = true;
        });

        Async::runMicrotasks();

        $this->assertTrue($caught);
    }

    public function testFinally(): void
    {
        $promise = Promise::resolve('value');
        $finallyCalled = false;

        $promise->finally(function () use (&$finallyCalled) {
            $finallyCalled = true;
        });

        Async::runMicrotasks();

        $this->assertTrue($finallyCalled);
    }

    public function testAll(): void
    {
        $promises = [
            Promise::resolve(1),
            Promise::resolve(2),
            Promise::resolve(3),
        ];

        $result = null;

        Promise::all($promises)->then(function ($values) use (&$result) {
            $result = $values;
        });

        Async::runMicrotasks();

        $this->assertEquals([1, 2, 3], $result);
    }

    public function testRace(): void
    {
        $promises = [
            Promise::resolve('first'),
            Promise::resolve('second'),
        ];

        $result = null;

        Promise::race($promises)->then(function ($value) use (&$result) {
            $result = $value;
        });

        Async::runMicrotasks();

        $this->assertEquals('first', $result);
    }

    public function testAllSettled(): void
    {
        $promises = [
            Promise::resolve('success'),
            Promise::reject('error'),
        ];

        $result = null;

        Promise::allSettled($promises)->then(function ($values) use (&$result) {
            $result = $values;
        });

        Async::runMicrotasks();

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertEquals('fulfilled', $result[0]['status']);
        $this->assertEquals('rejected', $result[1]['status']);
    }
}

final class AsyncTest extends TestCase
{
    public function testDefer(): void
    {
        $called = false;

        Async::defer(function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);

        Async::runDeferred();

        $this->assertTrue($called);
    }

    public function testQueueMicrotask(): void
    {
        $called = false;

        Async::queueMicrotask(function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);

        Async::runMicrotasks();

        $this->assertTrue($called);
    }

    public function testEventEmission(): void
    {
        $received = null;

        Async::on('custom_event', function ($data) use (&$received) {
            $received = $data;
        });

        Async::emit('custom_event', ['message' => 'hello']);

        $this->assertEquals(['message' => 'hello'], $received);
    }

    public function testPromisify(): void
    {
        $asyncFunc = Async::promisify(function ($value, $callback) {
            $callback(null, $value * 2);
        });

        $promise = $asyncFunc(5);
        $result = null;

        $promise->then(function ($value) use (&$result) {
            $result = $value;
        });

        Async::runMicrotasks();

        $this->assertEquals(10, $result);
    }

    protected function tearDown(): void
    {
        Async::reset();
    }
}
