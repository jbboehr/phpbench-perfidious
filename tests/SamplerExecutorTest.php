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

use jbboehr\PhpBenchPerfidious\Sampler\Measurement;
use jbboehr\PhpBenchPerfidious\Sampler\SamplerInterface;
use jbboehr\PhpBenchPerfidious\SamplerExecutor;
use jbboehr\PhpBenchPerfidious\SamplerResult;
use jbboehr\PhpBenchPerfidious\Tests\Fixtures\ExecutorFixtureBenchmark;
use jbboehr\PhpBenchPerfidious\Tests\Fixtures\FakeSampler;
use jbboehr\PhpBenchPerfidious\Tests\Fixtures\SamplerFixtureBenchmark;
use jbboehr\PhpBenchPerfidious\Tests\Fixtures\SamplerFixtureParameter;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Model\ParameterSet;
use PhpBench\Model\Result\TimeResult;
use PhpBench\Registry\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

final class SamplerExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        ExecutorFixtureBenchmark::$callCount = 0;
        SamplerFixtureBenchmark::$calls = [];
        SamplerFixtureBenchmark::$sampler = null;
    }

    protected function tearDown(): void
    {
        SamplerFixtureBenchmark::$sampler = null;
        SamplerFixtureBenchmark::$calls = [];
    }

    /**
     * @param list<string> $beforeMethods
     * @param list<string> $afterMethods
     */
    private function makeContext(
        string $methodName = 'increments',
        int $revolutions = 3,
        int $warmup = 0,
        array $beforeMethods = [],
        array $afterMethods = [],
    ): ExecutionContext {
        return new ExecutionContext(
            className: ExecutorFixtureBenchmark::class,
            classPath: __DIR__ . '/Fixtures/ExecutorFixtureBenchmark.php',
            methodName: $methodName,
            revolutions: $revolutions,
            beforeMethods: $beforeMethods,
            afterMethods: $afterMethods,
            warmup: $warmup,
        );
    }

    public function testReturnsRawAndPerRevolutionMetricsWithIndependentWallTime(): void
    {
        $sampler = new FakeSampler(9_000_999, ['cpu-time' => 12_000, 'page-faults' => 7]);
        $results = (new SamplerExecutor($sampler))->execute($this->makeContext(), new Config('test', []));

        $result = $results->byType(SamplerResult::class)->first();
        self::assertInstanceOf(SamplerResult::class, $result);
        self::assertSame(9_000_999, $result->elapsedTimeNs);
        self::assertSame(3, $result->revolutions);
        self::assertSame(12_000, $result->values['cpu_time_raw']);
        self::assertSame(4_000, $result->values['cpu_time']);
        self::assertSame(7, $result->values['page_faults_raw']);
        self::assertSame(7 / 3, $result->values['page_faults']);

        $time = $results->byType(TimeResult::class)->first();
        self::assertInstanceOf(TimeResult::class, $time);
        self::assertSame(['net' => 9_000, 'revs' => 3, 'avg' => 3_000], $time->getMetrics());
        self::assertSame(3, ExecutorFixtureBenchmark::$callCount);
        self::assertSame(1, $sampler->measurements);
    }

    public function testReturnsWallTimeWithoutACpuTimeMetric(): void
    {
        $results = (new SamplerExecutor(new FakeSampler(999, ['page-faults' => 0])))->execute(
            $this->makeContext(),
            new Config('test', []),
        );

        $time = $results->byType(TimeResult::class)->first();
        self::assertInstanceOf(TimeResult::class, $time);
        self::assertSame(0, $time->getNet());
    }

    /** @param array<string, mixed> $parameters */
    #[DataProvider('parameterSets')]
    public function testOnlyRevolutionsAreMeasuredAndAllCallsReceiveParameters(array $parameters): void
    {
        $sampler = new FakeSampler();
        SamplerFixtureBenchmark::$sampler = $sampler;
        $context = new ExecutionContext(
            className: SamplerFixtureBenchmark::class,
            classPath: __DIR__ . '/Fixtures/SamplerFixtureBenchmark.php',
            methodName: 'bench',
            revolutions: 3,
            beforeMethods: ['before', 'before'],
            afterMethods: ['after', 'after'],
            parameters: ParameterSet::fromUnserializedValues('test', $parameters),
            warmup: 2,
        );

        (new SamplerExecutor($sampler))->execute($context, new Config('test', []));

        self::assertSame([
            ['construct', [], false],
            ['before', $parameters, false],
            ['before', $parameters, false],
            ['bench', $parameters, false],
            ['bench', $parameters, false],
            ['bench', $parameters, true],
            ['bench', $parameters, true],
            ['bench', $parameters, true],
            ['after', $parameters, false],
            ['after', $parameters, false],
        ], SamplerFixtureBenchmark::$calls);
        self::assertSame(1, $sampler->measurements);
        self::assertFalse($sampler->measuring);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function parameterSets(): iterable
    {
        yield 'empty' => [[]];
        yield 'nonempty' => [['size' => 10, 'nested' => ['value' => 'payload']]];
    }

    public function testParameterMutationsAreSharedAcrossHooksWarmupAndRevolutions(): void
    {
        $sampler = new FakeSampler();
        SamplerFixtureBenchmark::$sampler = $sampler;
        $context = new ExecutionContext(
            SamplerFixtureBenchmark::class,
            __DIR__ . '/Fixtures/SamplerFixtureBenchmark.php',
            'mutatesParameters',
            revolutions: 2,
            beforeMethods: ['mutatesParameters'],
            afterMethods: ['mutatesParameters'],
            parameters: ParameterSet::fromUnserializedValues('test', ['count' => 0]),
            warmup: 1,
        );

        (new SamplerExecutor($sampler))->execute($context, new Config('test', []));

        self::assertSame([
            ['construct', [], false],
            ['mutate', ['count' => 1], false],
            ['mutate', ['count' => 2], false],
            ['mutate', ['count' => 3], true],
            ['mutate', ['count' => 4], true],
            ['mutate', ['count' => 5], false],
        ], SamplerFixtureBenchmark::$calls);
    }

    public function testUnserializesParametersOnceOutsideSampling(): void
    {
        $sampler = new FakeSampler();
        SamplerFixtureBenchmark::$sampler = $sampler;
        $context = new ExecutionContext(
            SamplerFixtureBenchmark::class,
            __DIR__ . '/Fixtures/SamplerFixtureBenchmark.php',
            'bench',
            parameters: ParameterSet::fromSerializedParameters('test', [
                'payload' => serialize(new SamplerFixtureParameter()),
            ]),
        );

        (new SamplerExecutor($sampler))->execute($context, new Config('test', []));

        self::assertSame([
            ['unserialize', [], false],
        ], array_values(array_filter(
            SamplerFixtureBenchmark::$calls,
            static fn (array $call): bool => 'unserialize' === $call[0],
        )));
    }

    /** @param class-string<\Throwable> $errorClass */
    #[DataProvider('failingMethods')]
    public function testWrapsBenchmarkFailuresAndCanExecuteAgain(string $method, string $errorClass): void
    {
        $sampler = new FakeSampler();
        $executor = new SamplerExecutor($sampler);
        try {
            $executor->execute($this->makeContext($method, afterMethods: ['increments']), new Config('test', []));
            self::fail('Expected an ExecutionError');
        } catch (ExecutionError $error) {
            self::assertInstanceOf($errorClass, $error->getPrevious());
            self::assertStringContainsString('deliberate', $error->getMessage());
        }
        self::assertSame(0, ExecutorFixtureBenchmark::$callCount);
        self::assertFalse($sampler->measuring);

        $executor->execute($this->makeContext(), new Config('test', []));
        self::assertSame(3, ExecutorFixtureBenchmark::$callCount);
        self::assertSame(2, $sampler->measurements);
    }

    /** @return iterable<string, array{string, class-string<\Throwable>}> */
    public static function failingMethods(): iterable
    {
        yield 'exception' => ['throwsException', \RuntimeException::class];
        yield 'error' => ['throwsError', \Error::class];
    }

    public function testWrapsSamplerFailuresAndPreservesTheOriginalError(): void
    {
        $cause = new \RuntimeException('sampler unavailable');
        $sampler = $this->createMock(SamplerInterface::class);
        $sampler->expects(self::once())->method('measure')->willThrowException($cause);

        try {
            (new SamplerExecutor($sampler))->execute($this->makeContext(), new Config('test', []));
            self::fail('Expected an ExecutionError');
        } catch (ExecutionError $error) {
            self::assertSame($cause, $error->getPrevious());
        }
        self::assertSame(0, ExecutorFixtureBenchmark::$callCount);
    }

    public function testSamplerFailureAfterOperationSkipsAfterMethods(): void
    {
        $cause = new \RuntimeException('sampler failed after operation');
        $sampler = new class ($cause) implements SamplerInterface {
            public function __construct(private readonly \Throwable $cause)
            {
            }

            public function measure(callable $operation): Measurement
            {
                $operation();
                throw $this->cause;
            }
        };

        try {
            (new SamplerExecutor($sampler))->execute(
                $this->makeContext(revolutions: 2, afterMethods: ['increments']),
                new Config('test', []),
            );
            self::fail('Expected an ExecutionError');
        } catch (ExecutionError $error) {
            self::assertSame($cause, $error->getPrevious());
        }

        self::assertSame(2, ExecutorFixtureBenchmark::$callCount);
    }

    #[DataProvider('invalidMethods')]
    public function testRejectsUncallableMethods(ExecutionContext $context, int $measurements): void
    {
        $sampler = new FakeSampler();
        try {
            (new SamplerExecutor($sampler))->execute($context, new Config('test', []));
            self::fail('Expected an ExecutionError');
        } catch (ExecutionError $error) {
            self::assertInstanceOf(\BadMethodCallException::class, $error->getPrevious());
            self::assertStringContainsString('Method is not callable: missing', $error->getMessage());
        }
        self::assertSame($measurements, $sampler->measurements);
    }

    /** @return iterable<string, array{ExecutionContext, int}> */
    public static function invalidMethods(): iterable
    {
        yield 'subject' => [new ExecutionContext(ExecutorFixtureBenchmark::class, '', 'missing'), 0];
        yield 'before' => [new ExecutionContext(
            ExecutorFixtureBenchmark::class,
            '',
            'increments',
            beforeMethods: ['missing'],
        ), 0];
        yield 'after' => [new ExecutionContext(
            ExecutorFixtureBenchmark::class,
            '',
            'increments',
            afterMethods: ['missing'],
        ), 1];
    }

    public function testLoadsBenchmarkFromClassPath(): void
    {
        $className = 'SamplerClassPathFixture';
        self::assertFalse(class_exists($className, false));
        $classPath = $this->writeBenchmarkFile($className);
        $context = new ExecutionContext($className, $classPath, 'passes');

        try {
            $results = (new SamplerExecutor(new FakeSampler()))->execute($context, new Config('test', []));
            self::assertInstanceOf(SamplerResult::class, $results->byType(SamplerResult::class)->first());
        } finally {
            unlink($classPath);
        }
    }

    public function testWrapsFailureWhenClassPathDoesNotDefineBenchmark(): void
    {
        $className = 'SamplerMissingClassFixture';
        self::assertFalse(class_exists($className, false));
        $context = new ExecutionContext($className, __FILE__, 'passes');
        $sampler = new FakeSampler();

        try {
            (new SamplerExecutor($sampler))->execute($context, new Config('test', []));
            self::fail('Expected an ExecutionError');
        } catch (ExecutionError $error) {
            self::assertInstanceOf(ExecutionError::class, $error->getPrevious());
            self::assertStringContainsString(
                sprintf('Benchmark class "%s" does not exist', $className),
                $error->getMessage(),
            );
        }

        self::assertSame(0, $sampler->measurements);
    }

    public function testBootstrapRunsOnceBeforeBenchmarkCreationAndSampling(): void
    {
        $bootstrap = tempnam(sys_get_temp_dir(), 'sampler-bootstrap-');
        self::assertNotFalse($bootstrap);
        file_put_contents($bootstrap, '<?php
            use jbboehr\PhpBenchPerfidious\Tests\Fixtures\SamplerFixtureBenchmark;
            SamplerFixtureBenchmark::$calls[] = [
                "bootstrap", [], SamplerFixtureBenchmark::$sampler->measuring ?? false,
            ];
        ');
        $sampler = new FakeSampler();
        SamplerFixtureBenchmark::$sampler = $sampler;
        $context = new ExecutionContext(
            SamplerFixtureBenchmark::class,
            __DIR__ . '/Fixtures/SamplerFixtureBenchmark.php',
            'bench',
        );

        try {
            $executor = new SamplerExecutor($sampler, $bootstrap);
            $executor->execute($context, new Config('test', []));
            $executor->execute($context, new Config('test', []));
        } finally {
            unlink($bootstrap);
        }

        self::assertSame([
            ['bootstrap', [], false],
            ['construct', [], false],
            ['bench', [], true],
            ['construct', [], false],
            ['bench', [], true],
        ], SamplerFixtureBenchmark::$calls);
    }

    public function testNativeFactoryPassesBootstrapThrough(): void
    {
        $className = 'SamplerBootstrapFixture';
        self::assertFalse(class_exists($className, false));
        $bootstrap = $this->writeBenchmarkFile($className);
        $context = new ExecutionContext($className, '', 'passes');

        try {
            $results = SamplerExecutor::withMetrics(bootstrap: $bootstrap)->execute($context, new Config('test', []));
            self::assertInstanceOf(SamplerResult::class, $results->byType(SamplerResult::class)->first());
        } finally {
            unlink($bootstrap);
        }
    }

    private function writeBenchmarkFile(string $className): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sampler-benchmark-');
        self::assertNotFalse($path);
        file_put_contents($path, sprintf('<?php class %s { public function passes(): void {} }', $className));

        return $path;
    }

    public function testNativeFactoryDefaultsToCpuTime(): void
    {
        $results = SamplerExecutor::withMetrics()->execute($this->makeContext('passes'), new Config('test', []));
        $result = $results->byType(SamplerResult::class)->first();
        self::assertInstanceOf(SamplerResult::class, $result);
        self::assertSame(['cpu_time_raw', 'cpu_time'], array_keys($result->values));
        self::assertGreaterThanOrEqual(0, $result->values['cpu_time_raw']);
        self::assertGreaterThan(0, $result->elapsedTimeNs);
        self::assertInstanceOf(TimeResult::class, $results->byType(TimeResult::class)->first());
    }

    #[RequiresOperatingSystem('Linux')]
    public function testNativeFactoryPreservesExplicitMetrics(): void
    {
        $results = SamplerExecutor::withMetrics(['page-faults'])->execute(
            $this->makeContext('passes'),
            new Config('test', []),
        );
        $result = $results->byType(SamplerResult::class)->first();
        self::assertInstanceOf(SamplerResult::class, $result);
        self::assertSame(['page_faults_raw', 'page_faults'], array_keys($result->values));
        self::assertInstanceOf(TimeResult::class, $results->byType(TimeResult::class)->first());
    }
}
