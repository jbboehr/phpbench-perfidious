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

use jbboehr\PhpBenchPerfidious\PerfidiousExecutor;
use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use jbboehr\PhpBenchPerfidious\Tests\Fixtures\ExecutorFixtureBenchmark;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Model\Result\TimeResult;
use PhpBench\Registry\Config;
use PHPUnit\Framework\TestCase;

class PerfidiousExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        ExecutorFixtureBenchmark::$callCount = 0;
    }

    private function makeExecutor(): PerfidiousExecutor
    {
        // Software-only metric: reliable in sandboxed/virtualized CI environments
        // where hardware PMU counters are not available.
        return new PerfidiousExecutor(metrics: ['perf::PERF_COUNT_SW_CPU_CLOCK']);
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
        // perf::PERF_COUNT_SW_CPU_CLOCK is in PerfidiousExecutor::TIME_EVENTS.
        $results = $this->makeExecutor()->execute($this->makeContext('passes'), new Config('test', []));

        $this->assertInstanceOf(TimeResult::class, $results->byType(TimeResult::class)->first());
    }

    public function testTimeResultIsNotAddedWhenNoMetricIsARecognizedTimeEvent(): void
    {
        $executor = new PerfidiousExecutor(metrics: ['perf::PERF_COUNT_SW_PAGE_FAULTS']);

        $results = $executor->execute($this->makeContext('passes'), new Config('test', []));

        $this->assertCount(0, $results->byType(TimeResult::class));
    }

    public function testExceptionFromBenchmarkMethodIsWrappedAsExecutionError(): void
    {
        $this->expectException(ExecutionError::class);

        $this->makeExecutor()->execute($this->makeContext('throwsException'), new Config('test', []));
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
}
