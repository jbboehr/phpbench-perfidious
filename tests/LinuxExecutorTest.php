<?php

/**
 * Copyright (c) anno Domini nostri Jesu Christi MMXXIV John Boehr & contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-only WITH romic-exception
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License version 3,
 * as published by the Free Software Foundation, together with the Romic
 * Exception (an additional permission under section 7 of that license).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * and the Romic Exception along with this program.  If not, see
 * <http://www.gnu.org/licenses/> and the LICENSE_EXCEPTION file.
 */

namespace jbboehr\PhpBenchPerfidious\Tests;

use InvalidArgumentException;
use jbboehr\PhpBenchPerfidious\Linux\NativeHandle;
use jbboehr\PhpBenchPerfidious\LinuxExecutor;
use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use jbboehr\PhpBenchPerfidious\Tests\Fixtures\ExecutorFixtureBenchmark;
use jbboehr\PhpBenchPerfidious\Tests\Fixtures\FakeHandle;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Model\Result\TimeResult;
use PhpBench\Registry\Config;
use PHPUnit\Framework\TestCase;

class LinuxExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        ExecutorFixtureBenchmark::$callCount = 0;
        ExecutorFixtureBenchmark::$argumentCounts = [];
    }

    private function makeExecutor(): LinuxExecutor
    {
        // Software-only metric: reliable in sandboxed/virtualized CI environments
        // where hardware PMU counters are not available.
        return LinuxExecutor::withMetrics(metrics: ['perf::PERF_COUNT_SW_CPU_CLOCK']);
    }

    /**
     * @param list<string> $beforeMethods
     * @param list<string> $afterMethods
     */
    private function makeContext(
        string $methodName,
        int $revolutions = 1,
        int $warmup = 0,
        array $beforeMethods = [],
        array $afterMethods = [],
    ): ExecutionContext {
        return new ExecutionContext(
            className: ExecutorFixtureBenchmark::class,
            classPath: (string) (new \ReflectionClass(ExecutorFixtureBenchmark::class))->getFileName(),
            methodName: $methodName,
            revolutions: $revolutions,
            beforeMethods: $beforeMethods,
            afterMethods: $afterMethods,
            warmup: $warmup,
        );
    }

    public function testExecuteReturnsPerfidiousResult(): void
    {
        $results = $this->makeExecutor()->execute($this->makeContext('passes', 5), new Config('test', []));

        $result = $results->byType(PerfidiousResult::class)->first();

        $this->assertInstanceOf(PerfidiousResult::class, $result);
        $this->assertSame(5, $result->revolutions);

        // The executor was constructed with a single, non-default metric; if the
        // constructor's `$metrics ?? self::DEFAULT_METRICS` fallback were ever
        // (mis)applied instead of the given value, HW_INSTRUCTIONS (part of
        // DEFAULT_METRICS but not requested here) would show up too.
        $this->assertArrayNotHasKey('perf__PERF_COUNT_HW_INSTRUCTIONS_raw', $result->values);
    }

    public function testTimeResultIsAddedWhenMetricIsARecognizedTimeEvent(): void
    {
        // perf::PERF_COUNT_SW_CPU_CLOCK is in LinuxExecutor::TIME_EVENTS.
        $results = $this->makeExecutor()->execute($this->makeContext('passes'), new Config('test', []));

        $this->assertInstanceOf(TimeResult::class, $results->byType(TimeResult::class)->first());
    }

    public function testTimeResultIsNotAddedWhenNoMetricIsARecognizedTimeEvent(): void
    {
        $executor = LinuxExecutor::withMetrics(metrics: ['perf::PERF_COUNT_SW_PAGE_FAULTS']);

        $results = $executor->execute($this->makeContext('passes'), new Config('test', []));

        $this->assertCount(0, $results->byType(TimeResult::class));
    }

    public function testWithMetricsRejectsMultipleRecognizedTimeEvents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At most one recognized time event is supported');

        LinuxExecutor::withMetrics([
            'perf::PERF_COUNT_SW_CPU_CLOCK',
            'perf::PERF_COUNT_SW_TASK_CLOCK',
        ]);
    }

    public function testExecuteRejectsMultipleTimeEventsReturnedByAHandle(): void
    {
        $handle = new FakeHandle(values: [
            'perf::PERF_COUNT_SW_CPU_CLOCK' => 1_000_000,
            'perf::PERF_COUNT_SW_TASK_CLOCK' => 2_000_000,
        ]);

        $this->expectException(ExecutionError::class);
        $this->expectExceptionMessage('At most one recognized time event is supported');

        (new LinuxExecutor($handle))->execute($this->makeContext('passes'), new Config('test', []));
    }

    public function testExceptionFromBenchmarkMethodIsWrappedAsExecutionError(): void
    {
        try {
            $this->makeExecutor()->execute($this->makeContext('throwsException'), new Config('test', []));
            $this->fail('Expected an ExecutionError to be thrown');
        } catch (ExecutionError $error) {
            $previous = $error->getPrevious();
            $this->assertInstanceOf(\RuntimeException::class, $previous);
            $this->assertSame('deliberate exception from benchmark', $previous->getMessage());
        }
    }

    public function testHandleIsDisabledWhenBenchmarkMethodThrows(): void
    {
        $handle = new FakeHandle();
        $executor = new LinuxExecutor($handle);

        try {
            $executor->execute($this->makeContext('throwsException'), new Config('test', []));
            $this->fail('Expected an ExecutionError to be thrown');
        } catch (ExecutionError) {
            $this->assertSame(['reset', 'read', 'enable', 'disable'], $handle->calls);
        }
    }

    public function testErrorFromBenchmarkMethodIsWrappedAsExecutionError(): void
    {
        // Regression test: \Error (e.g. TypeError, "call to undefined method") is not
        // an \Exception, so execute() must catch \Throwable to wrap it into an
        // ExecutionError instead of letting it crash the runner uncaught.
        $this->expectException(ExecutionError::class);

        $this->makeExecutor()->execute($this->makeContext('throwsError'), new Config('test', []));
    }

    public function testMissingMethodIsWrappedAsExecutionError(): void
    {
        try {
            $this->makeExecutor()->execute($this->makeContext('doesNotExist'), new Config('test', []));
            $this->fail('Expected an ExecutionError to be thrown');
        } catch (ExecutionError $e) {
            $this->assertStringContainsString(
                'Method does not exist: doesNotExist on ' . ExecutorFixtureBenchmark::class,
                $e->getMessage(),
            );
        }
    }

    public function testMissingBeforeMethodHasAnExplicitDiagnostic(): void
    {
        try {
            $this->makeExecutor()->execute(
                $this->makeContext('passes', beforeMethods: ['doesNotExist']),
                new Config('test', []),
            );
            $this->fail('Expected an ExecutionError to be thrown');
        } catch (ExecutionError $error) {
            $this->assertStringContainsString('Before method does not exist: doesNotExist', $error->getMessage());
            $this->assertInstanceOf(\BadMethodCallException::class, $error->getPrevious());
        }
    }

    public function testMissingAfterMethodHasAnExplicitDiagnostic(): void
    {
        try {
            $this->makeExecutor()->execute(
                $this->makeContext('passes', afterMethods: ['doesNotExist']),
                new Config('test', []),
            );
            $this->fail('Expected an ExecutionError to be thrown');
        } catch (ExecutionError $error) {
            $this->assertStringContainsString('After method does not exist: doesNotExist', $error->getMessage());
            $this->assertInstanceOf(\BadMethodCallException::class, $error->getPrevious());
        }
    }

    public function testCreatesBenchmarkFromClassPathWhenNotAlreadyAutoloaded(): void
    {
        $context = new ExecutionContext(
            className: 'NotAutoloadedFixtureBenchmark',
            classPath: __DIR__ . '/Fixtures/NotAutoloadedFixtureBenchmark.php',
            methodName: 'passes',
        );

        $results = $this->makeExecutor()->execute($context, new Config('test', []));

        $this->assertInstanceOf(PerfidiousResult::class, $results->byType(PerfidiousResult::class)->first());
    }

    public function testRevolutionsLoopRunsExactlyOncePerRevolution(): void
    {
        $this->makeExecutor()->execute($this->makeContext('increments', revolutions: 7), new Config('test', []));

        $this->assertSame(7, ExecutorFixtureBenchmark::$callCount);
    }

    public function testWarmupLoopRunsExactlyOncePerWarmupRevolution(): void
    {
        $this->makeExecutor()->execute(
            $this->makeContext('increments', revolutions: 1, warmup: 4),
            new Config('test', []),
        );

        // 4 warmup calls + 1 real revolution.
        $this->assertSame(5, ExecutorFixtureBenchmark::$callCount);
    }

    public function testWarmupAndMeasuredCallsAlwaysReceiveTheParameterArray(): void
    {
        $this->makeExecutor()->execute(
            $this->makeContext('recordsArgumentCount', revolutions: 3, warmup: 2),
            new Config('test', []),
        );

        $this->assertSame([1, 1, 1, 1, 1], ExecutorFixtureBenchmark::$argumentCounts);
    }

    public function testBeforeMethodsRunBeforeExecution(): void
    {
        $this->makeExecutor()->execute(
            $this->makeContext('passes', beforeMethods: ['increments', 'increments']),
            new Config('test', []),
        );

        $this->assertSame(2, ExecutorFixtureBenchmark::$callCount);
    }

    public function testAfterMethodsRunAfterExecution(): void
    {
        $this->makeExecutor()->execute(
            $this->makeContext('passes', afterMethods: ['increments', 'increments', 'increments']),
            new Config('test', []),
        );

        $this->assertSame(3, ExecutorFixtureBenchmark::$callCount);
    }

    /**
     * @dataProvider adjustedTimeProvider
     */
    public function testAdjustedTime(int|float $count, int $timeEnabled, int $timeRunning, int $expected): void
    {
        $this->assertSame($expected, LinuxExecutor::adjustedTime($count, $timeEnabled, $timeRunning));
    }

    /**
     * @return iterable<string, array{int|float, int, int, int}>
     */
    public static function adjustedTimeProvider(): iterable
    {
        // No multiplexing: timeEnabled === timeRunning, count is already in
        // nanoseconds, /1e3 converts to microseconds.
        yield 'no multiplexing' => [5_000_000, 1_000_000, 1_000_000, 5_000];
        // Counter was only actually running half the time it was enabled for
        // (multiplexed with other counters): the kernel-reported count gets
        // scaled up by timeEnabled/timeRunning to estimate the true value.
        yield 'multiplexed at half' => [5_000_000, 1_000_000, 500_000, 10_000];
        // Float count (as decoded from a remote executor's child process).
        yield 'float count' => [5_000_000.0, 1_000_000, 1_000_000, 5_000];
    }

    public function testAssertCountersRanThrowsWhenTimeRunningIsZero(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('perf_events failed to run');

        LinuxExecutor::assertCountersRan(0);
    }

    public function testAssertCountersRanThrowsWhenTimeRunningIsNegative(): void
    {
        $this->expectException(\RuntimeException::class);

        LinuxExecutor::assertCountersRan(-1);
    }

    public function testAssertCountersRanDoesNotThrowWhenTimeRunningIsPositive(): void
    {
        $this->expectNotToPerformAssertions();

        LinuxExecutor::assertCountersRan(1);
    }

    public function testHandleLifecycleCallOrder(): void
    {
        $handle = new FakeHandle(values: ['perf::PERF_COUNT_SW_CPU_CLOCK' => 5_000_000]);
        $executor = new LinuxExecutor($handle);

        $executor->execute($this->makeContext('passes', 3), new Config('test', []));

        // Capture the lifetime timing baseline after reset, while disabled,
        // then bracket the measured loop with enable/disable and read its result.
        $this->assertSame(['reset', 'read', 'enable', 'disable', 'read'], $handle->calls);
    }

    public function testTimeRunningZeroFromHandleIsWrappedAsExecutionError(): void
    {
        $handle = new FakeHandle(timeRunning: 0, timeEnabled: 0);
        $executor = new LinuxExecutor($handle);

        $this->expectException(ExecutionError::class);

        $executor->execute($this->makeContext('passes'), new Config('test', []));
    }

    public function testExecuteComputesExactValuesFromControlledHandleData(): void
    {
        $timeEnabled = 1_000_000;
        $timeRunning = 1_000_000;
        $count = 5_000_000;
        $revolutions = 10;

        $handle = new FakeHandle(
            timeRunning: $timeRunning,
            timeEnabled: $timeEnabled,
            values: ['perf::PERF_COUNT_SW_CPU_CLOCK' => $count],
        );
        $executor = new LinuxExecutor($handle);

        $results = $executor->execute($this->makeContext('passes', $revolutions), new Config('test', []));

        $perfResult = $results->byType(PerfidiousResult::class)->first();
        $this->assertInstanceOf(PerfidiousResult::class, $perfResult);
        $this->assertSame(
            $count * $timeEnabled / $timeRunning / $revolutions,
            $perfResult->values['perf__PERF_COUNT_SW_CPU_CLOCK'],
        );

        $timeResult = $results->byType(TimeResult::class)->first();
        $this->assertInstanceOf(TimeResult::class, $timeResult);
        $this->assertSame(
            LinuxExecutor::adjustedTime($count, $timeEnabled, $timeRunning),
            $timeResult->getNet(),
        );
    }

    public function testReusedHandleScalesWithTheCurrentIntervalTimings(): void
    {
        $handle = new FakeHandle(values: ['perf::PERF_COUNT_SW_CPU_CLOCK' => 5_000_000]);
        $executor = new LinuxExecutor($handle);
        $context = $this->makeContext('passes', revolutions: 10);
        $config = new Config('test', []);

        $executor->execute($context, $config);
        $handle->timeEnabled = 2_000_000;
        $handle->timeRunning = 500_000;
        $results = $executor->execute($context, $config);

        $perfResult = $results->byType(PerfidiousResult::class)->first();
        self::assertInstanceOf(PerfidiousResult::class, $perfResult);
        self::assertSame(2_000_000, $perfResult->values['perf__PERF_COUNT_SW_CPU_CLOCK']);
        self::assertSame(5_000_000, $perfResult->values['perf__PERF_COUNT_SW_CPU_CLOCK_raw']);
        self::assertSame(2_000_000, $perfResult->timeEnabled);
        self::assertSame(500_000, $perfResult->timeRunning);

        $timeResult = $results->byType(TimeResult::class)->first();
        self::assertInstanceOf(TimeResult::class, $timeResult);
        self::assertSame(20_000, $timeResult->getNet());
    }

    public function testReusedHandleRejectsAnIntervalWhoseCountersDidNotRun(): void
    {
        $handle = new FakeHandle(values: ['perf::PERF_COUNT_SW_CPU_CLOCK' => 5_000_000]);
        $executor = new LinuxExecutor($handle);
        $context = $this->makeContext('passes', revolutions: 10);
        $config = new Config('test', []);

        $executor->execute($context, $config);
        $handle->timeEnabled = 2_000_000;
        $handle->timeRunning = 0;

        try {
            $executor->execute($context, $config);
            self::fail('Expected an ExecutionError to be thrown');
        } catch (ExecutionError $error) {
            self::assertStringContainsString('perf_events failed to run', $error->getMessage());
        }

        // The rejected interval still advanced the lifetime enabled time. A retry
        // must baseline that failed interval away and report only its own timings.
        $handle->timeEnabled = 3_000_000;
        $handle->timeRunning = 1_000_000;
        $results = $executor->execute($context, $config);

        $perfResult = $results->byType(PerfidiousResult::class)->first();
        self::assertInstanceOf(PerfidiousResult::class, $perfResult);
        self::assertSame(3_000_000, $perfResult->timeEnabled);
        self::assertSame(1_000_000, $perfResult->timeRunning);
        self::assertSame(1_500_000, $perfResult->values['perf__PERF_COUNT_SW_CPU_CLOCK']);

        $timeResult = $results->byType(TimeResult::class)->first();
        self::assertInstanceOf(TimeResult::class, $timeResult);
        self::assertSame(15_000, $timeResult->getNet());
    }

    public function testBenchmarkFailureDoesNotPolluteTheNextInterval(): void
    {
        $handle = new FakeHandle(
            timeRunning: 4_000_000,
            timeEnabled: 8_000_000,
            values: ['perf::PERF_COUNT_SW_CPU_CLOCK' => 6_000_000],
        );
        $executor = new LinuxExecutor($handle);
        $config = new Config('test', []);

        try {
            $executor->execute($this->makeContext('throwsException'), $config);
            self::fail('Expected an ExecutionError to be thrown');
        } catch (ExecutionError) {
        }

        $handle->timeEnabled = 3_000_000;
        $handle->timeRunning = 1_000_000;
        $results = $executor->execute($this->makeContext('passes', revolutions: 2), $config);

        $perfResult = $results->byType(PerfidiousResult::class)->first();
        self::assertInstanceOf(PerfidiousResult::class, $perfResult);
        self::assertSame(3_000_000, $perfResult->timeEnabled);
        self::assertSame(1_000_000, $perfResult->timeRunning);
        self::assertSame(9_000_000, $perfResult->values['perf__PERF_COUNT_SW_CPU_CLOCK']);

        $timeResult = $results->byType(TimeResult::class)->first();
        self::assertInstanceOf(TimeResult::class, $timeResult);
        self::assertSame(18_000, $timeResult->getNet());
        self::assertSame([
            'reset', 'read', 'enable', 'disable',
            'reset', 'read', 'enable', 'disable', 'read',
        ], $handle->calls);
    }

    public function testReusedNativeHandleReportsIntervalTimings(): void
    {
        $handle = new NativeHandle(['perf::PERF_COUNT_SW_CPU_CLOCK']);
        $executor = new LinuxExecutor($handle);
        $config = new Config('test', []);

        $executor->execute($this->makeContext('passes', revolutions: 100), $config);
        $before = $handle->read();
        $results = $executor->execute($this->makeContext('passes'), $config);
        $after = $handle->read();

        $perfResult = $results->byType(PerfidiousResult::class)->first();
        self::assertInstanceOf(PerfidiousResult::class, $perfResult);
        self::assertGreaterThan(0, $before->timeRunning);
        self::assertSame($after->timeEnabled - $before->timeEnabled, $perfResult->timeEnabled);
        self::assertSame($after->timeRunning - $before->timeRunning, $perfResult->timeRunning);
    }
}
