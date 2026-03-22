<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use PHPUnit\Framework\TestCase;
use Kode\Process\Debug\ProcessDebugger;

final class ProcessDebuggerTest extends TestCase
{
    protected function setUp(): void
    {
        ProcessDebugger::enable();
        ProcessDebugger::clearTraces();
    }

    protected function tearDown(): void
    {
        ProcessDebugger::disable();
    }

    public function testEnableDisable(): void
    {
        ProcessDebugger::enable();
        $this->assertTrue(ProcessDebugger::isEnabled());

        ProcessDebugger::disable();
        $this->assertFalse(ProcessDebugger::isEnabled());
    }

    public function testStartEndTrace(): void
    {
        $id = ProcessDebugger::startTrace('test');
        $this->assertNotEmpty($id);

        $trace = ProcessDebugger::endTrace($id);
        
        $this->assertIsArray($trace);
        $this->assertEquals('test', $trace['name']);
        $this->assertNotNull($trace['duration']);
    }

    public function testProfile(): void
    {
        $result = ProcessDebugger::profile(function () {
            usleep(1000);
            return 'test_result';
        }, 'profile_test');

        $this->assertEquals('test_result', $result);
    }

    public function testGetAllTraces(): void
    {
        $id1 = ProcessDebugger::startTrace('trace1');
        $id2 = ProcessDebugger::startTrace('trace2');
        
        ProcessDebugger::endTrace($id1);
        ProcessDebugger::endTrace($id2);

        $traces = ProcessDebugger::getAllTraces();
        
        $this->assertCount(2, $traces);
    }

    public function testSlowTrace(): void
    {
        ProcessDebugger::setSlowThreshold(0.001);

        $id = ProcessDebugger::startTrace('slow_trace');
        usleep(2000);
        ProcessDebugger::endTrace($id);

        $slowTraces = ProcessDebugger::getSlowTraces();
        
        $this->assertGreaterThanOrEqual(1, count($slowTraces));
    }

    public function testClearTraces(): void
    {
        $id = ProcessDebugger::startTrace('test');
        ProcessDebugger::endTrace($id);

        $this->assertGreaterThan(0, count(ProcessDebugger::getAllTraces()));

        ProcessDebugger::clearTraces();
        $this->assertCount(0, ProcessDebugger::getAllTraces());
    }

    public function testGetMemoryUsage(): void
    {
        $memory = ProcessDebugger::getMemoryUsage();

        $this->assertArrayHasKey('usage', $memory);
        $this->assertArrayHasKey('peak', $memory);
        $this->assertArrayHasKey('formatted', $memory);
        $this->assertGreaterThan(0, $memory['usage']);
    }

    public function testGetBacktrace(): void
    {
        $trace = ProcessDebugger::getBacktrace(5);

        $this->assertIsArray($trace);
        $this->assertLessThanOrEqual(5, count($trace));
    }

    public function testFormatBacktrace(): void
    {
        $trace = ProcessDebugger::getBacktrace(3);
        $formatted = ProcessDebugger::formatBacktrace($trace);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('#0', $formatted);
    }

    public function testGenerateReport(): void
    {
        $id = ProcessDebugger::startTrace('report_test');
        ProcessDebugger::endTrace($id);

        $report = ProcessDebugger::generateReport();

        $this->assertArrayHasKey('memory', $report);
        $this->assertArrayHasKey('resources', $report);
        $this->assertArrayHasKey('traces', $report);
    }

    public function testDisabledTracing(): void
    {
        ProcessDebugger::disable();
        
        $id = ProcessDebugger::startTrace('disabled_test');
        $this->assertEmpty($id);
    }
}
