<?php
/**
 * Copyright (c) anno Domini nostri Jesu Christi MMXXIV John Boehr & contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace jbboehr\PhpBenchPerfidious\Tests;

use jbboehr\PhpBenchPerfidious\PerfidiousExecutor;
use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use jbboehr\PhpBenchPerfidious\Tests\Fixtures\ExecutorFixtureBenchmark;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Registry\Config;
use PHPUnit\Framework\TestCase;

class PerfidiousExecutorTest extends TestCase
{
    private function makeExecutor(): PerfidiousExecutor
    {
        // Software-only metric: reliable in sandboxed/virtualized CI environments
        // where hardware PMU counters are not available.
        return new PerfidiousExecutor(metrics: ['perf::PERF_COUNT_SW_CPU_CLOCK']);
    }

    private function makeContext(string $methodName, int $revolutions = 1): ExecutionContext
    {
        return new ExecutionContext(
            className: ExecutorFixtureBenchmark::class,
            classPath: (string) (new \ReflectionClass(ExecutorFixtureBenchmark::class))->getFileName(),
            methodName: $methodName,
            revolutions: $revolutions,
        );
    }

    public function testExecuteReturnsPerfidiousResult(): void
    {
        $results = $this->makeExecutor()->execute($this->makeContext('passes', 5), new Config('test', []));

        $result = $results->byType(PerfidiousResult::class)->first();

        $this->assertInstanceOf(PerfidiousResult::class, $result);
        $this->assertSame(5, $result->revolutions);
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
        $this->expectException(ExecutionError::class);

        $this->makeExecutor()->execute($this->makeContext('doesNotExist'), new Config('test', []));
    }
}
