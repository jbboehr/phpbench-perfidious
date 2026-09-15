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

use jbboehr\PhpBenchPerfidious\Executor\InitializingMethodExecutor;
use jbboehr\PhpBenchPerfidious\Executor\LinuxRemoteExecutor;
use jbboehr\PhpBenchPerfidious\LinuxExecutor;
use jbboehr\PhpBenchPerfidious\PerfidiousExtension;
use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use jbboehr\PhpBenchPerfidious\Progress\PerfidiousProgressLogger;
use jbboehr\PhpBenchPerfidious\Report\PerfidiousGenerator;
use jbboehr\PhpBenchPerfidious\SamplerExecutor;
use jbboehr\PhpBenchPerfidious\SamplerResult;
use jbboehr\PhpBenchPerfidious\Tests\Fixtures\ExecutorFixtureBenchmark;
use PhpBench\DependencyInjection\Container;
use PhpBench\Executor\CompositeExecutor;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Executor\Method\ErrorHandlingExecutorDecorator;
use PhpBench\Executor\Method\LocalMethodExecutor;
use PhpBench\Executor\Method\RemoteMethodExecutor;
use PhpBench\Executor\MethodExecutorContext;
use PhpBench\Extension\ConsoleExtension;
use PhpBench\Extension\CoreExtension;
use PhpBench\Extension\ExpressionExtension;
use PhpBench\Extension\ReportExtension;
use PhpBench\Extension\RunnerExtension;
use PhpBench\Extension\StorageExtension;
use PhpBench\Extensions\XDebug\XDebugExtension;
use PhpBench\Registry\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\Process\Process;

class PerfidiousExtensionTest extends TestCase
{
    /** @param array<string, mixed> $parameters */
    private function makeContainer(array $parameters = []): Container
    {
        $container = new Container([
            CoreExtension::class,
            RunnerExtension::class,
            ReportExtension::class,
            ExpressionExtension::class,
            StorageExtension::class,
            XDebugExtension::class,
            ConsoleExtension::class,
            PerfidiousExtension::class,
        ], array_replace([
            // Software-only metric: reliable in sandboxed/virtualized CI environments
            // where hardware PMU counters are not available.
            PerfidiousExtension::PARAM_PERFIDIOUS_LINUX_METRICS => ['perf::PERF_COUNT_SW_CPU_CLOCK'],
            // Needed for LinuxRemoteExecutor's child process to autoload fixture classes.
            RunnerExtension::PARAM_BOOTSTRAP => __DIR__ . '/bootstrap.php',
        ], $parameters));
        $container->init();

        return $container;
    }

    public function testConfigureSetsDefaultParameters(): void
    {
        $container = new Container([PerfidiousExtension::class]);
        $container->init();

        $this->assertSame(
            LinuxExecutor::DEFAULT_METRICS,
            $container->getParameter(PerfidiousExtension::PARAM_PERFIDIOUS_LINUX_METRICS)
        );
        $this->assertSame(['cpu-time'], $container->getParameter(PerfidiousExtension::PARAM_PERFIDIOUS_METRICS));
        $this->assertIsString($container->getParameter(PerfidiousExtension::PARAM_PROGRESS_SUMMARY_FORMAT));
        $this->assertIsString($container->getParameter(PerfidiousExtension::PARAM_PROGRESS_SUMMARY_BASELINE_FORMAT));
    }

    public function testConfigureRejectsNonStringMetricValues(): void
    {
        $resolver = new OptionsResolver();
        (new PerfidiousExtension())->configure($resolver);

        $this->expectException(InvalidOptionsException::class);

        $resolver->resolve([
            PerfidiousExtension::PARAM_PERFIDIOUS_LINUX_METRICS => ['perf::PERF_COUNT_SW_CPU_CLOCK', 42],
        ]);
    }

    public function testConfigureRejectsNonStringProgressFormats(): void
    {
        $resolver = new OptionsResolver();
        (new PerfidiousExtension())->configure($resolver);

        $this->expectException(InvalidOptionsException::class);

        $resolver->resolve([
            PerfidiousExtension::PARAM_PROGRESS_SUMMARY_FORMAT => ['not a string'],
        ]);
    }

    #[DataProvider('invalidSamplerMetrics')]
    public function testConfigureRejectsInvalidSamplerMetrics(mixed $metrics): void
    {
        $resolver = new OptionsResolver();
        (new PerfidiousExtension())->configure($resolver);

        $this->expectException(InvalidOptionsException::class);
        $this->expectExceptionMessage('perfidious.metrics');

        $resolver->resolve([PerfidiousExtension::PARAM_PERFIDIOUS_METRICS => $metrics]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSamplerMetrics(): iterable
    {
        yield 'scalar' => ['cpu-time'];
        yield 'null' => [null];
        yield 'empty' => [[]];
        yield 'unknown' => [['cpu-time', 'unknown']];
        yield 'Linux event name' => [['perf::PERF_COUNT_SW_CPU_CLOCK']];
        yield 'non-string' => [['cpu-time', 42]];
        yield 'duplicate' => [['cpu-time', 'page-faults', 'cpu-time']];
        yield 'associative' => [['metric' => 'cpu-time']];
        yield 'sparse' => [[1 => 'cpu-time']];
    }

    public function testConfigureAcceptsAllSamplerMetricNames(): void
    {
        $metrics = ['instructions', 'cpu-cycles', 'context-switches', 'page-faults', 'cpu-time'];
        $resolver = new OptionsResolver();
        (new PerfidiousExtension())->configure($resolver);

        $resolved = $resolver->resolve([PerfidiousExtension::PARAM_PERFIDIOUS_METRICS => $metrics]);

        self::assertSame($metrics, $resolved[PerfidiousExtension::PARAM_PERFIDIOUS_METRICS]);
    }

    public function testConfigurationDoesNotRequireTheNativeExtension(): void
    {
        $script = sprintf(
            'require %s;
            $container = new PhpBench\\DependencyInjection\\Container([
                jbboehr\\PhpBenchPerfidious\\PerfidiousExtension::class,
            ]);
            $container->init();
            echo json_encode($container->getParameter("perfidious.metrics"));',
            var_export(__DIR__ . '/bootstrap.php', true),
        );
        $process = new Process([PHP_BINARY, '-n', '-r', $script]);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());
        self::assertSame('["cpu-time"]', $process->getOutput());
    }

    public function testRegistersSamplerExecutorUnderPerfidiousTag(): void
    {
        $container = $this->makeContainer();
        $tagged = $container->getServiceIdsForTag(RunnerExtension::TAG_EXECUTOR);

        self::assertArrayHasKey(SamplerExecutor::class . '.composite', $tagged);
        self::assertSame('perfidious', $tagged[SamplerExecutor::class . '.composite']['name']);

        $executor = $container->get(SamplerExecutor::class . '.composite');
        self::assertInstanceOf(CompositeExecutor::class, $executor);

        $benchmarkExecutorProperty = new ReflectionProperty(CompositeExecutor::class, 'benchmarkExecutor');
        self::assertInstanceOf(SamplerExecutor::class, $benchmarkExecutorProperty->getValue($executor));

        $methodExecutorProperty = new ReflectionProperty(CompositeExecutor::class, 'methodExecutor');
        $decorator = $methodExecutorProperty->getValue($executor);
        self::assertInstanceOf(ErrorHandlingExecutorDecorator::class, $decorator);

        $innerExecutorProperty = new ReflectionProperty(ErrorHandlingExecutorDecorator::class, 'executor');
        $initializer = $innerExecutorProperty->getValue($decorator);
        self::assertInstanceOf(InitializingMethodExecutor::class, $initializer);
        $localExecutorProperty = new ReflectionProperty(InitializingMethodExecutor::class, 'executor');
        self::assertInstanceOf(LocalMethodExecutor::class, $localExecutorProperty->getValue($initializer));
    }

    public function testSamplerExecutorWrapsBootstrapInitializationFailures(): void
    {
        $bootstrap = tempnam(sys_get_temp_dir(), 'sampler-bootstrap-failure-');
        self::assertNotFalse($bootstrap);
        file_put_contents($bootstrap, '<?php throw new \LogicException("bootstrap failed");');

        try {
            $executor = $this->makeContainer([
                RunnerExtension::PARAM_BOOTSTRAP => $bootstrap,
            ])->get(SamplerExecutor::class . '.composite');
            self::assertInstanceOf(CompositeExecutor::class, $executor);

            $executor->executeMethods(
                new MethodExecutorContext(__FILE__, ExecutorFixtureBenchmark::class),
                ['beforeClass'],
            );
            self::fail('Expected an ExecutionError');
        } catch (ExecutionError $error) {
            self::assertSame(
                sprintf(
                    'Could not execute method(s) "beforeClass" on "%s"',
                    ExecutorFixtureBenchmark::class,
                ),
                $error->getMessage(),
            );
            self::assertInstanceOf(\LogicException::class, $error->getPrevious());
            self::assertSame('bootstrap failed', $error->getPrevious()->getMessage());
        } finally {
            unlink($bootstrap);
        }
    }

    public function testSamplerBootstrapAndDefaultMetricsReachTheExecutor(): void
    {
        $bootstrap = tempnam(sys_get_temp_dir(), 'sampler-extension-bootstrap-');
        self::assertNotFalse($bootstrap);
        file_put_contents($bootstrap, '<?php
            jbboehr\PhpBenchPerfidious\Tests\Fixtures\ExecutorFixtureBenchmark::$callCount = 40;
        ');
        ExecutorFixtureBenchmark::$callCount = 0;

        try {
            $container = $this->makeContainer([RunnerExtension::PARAM_BOOTSTRAP => $bootstrap]);
            $executor = $container->get(SamplerExecutor::class);
            self::assertInstanceOf(SamplerExecutor::class, $executor);
            $results = $executor->execute(
                new ExecutionContext(ExecutorFixtureBenchmark::class, '', 'increments'),
                new Config('test', []),
            );

            self::assertSame(41, ExecutorFixtureBenchmark::$callCount);
            $result = $results->byType(SamplerResult::class)->first();
            self::assertInstanceOf(SamplerResult::class, $result);
            self::assertSame(['cpu_time_raw', 'cpu_time'], array_keys($result->values));
        } finally {
            unlink($bootstrap);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    public function testCustomSamplerMetricsAreIndependentOfLinuxMetrics(): void
    {
        $container = $this->makeContainer([
            PerfidiousExtension::PARAM_PERFIDIOUS_METRICS => ['page-faults', 'cpu-time'],
        ]);
        self::assertSame(
            ['perf::PERF_COUNT_SW_CPU_CLOCK'],
            $container->getParameter(PerfidiousExtension::PARAM_PERFIDIOUS_LINUX_METRICS),
        );
        $executor = $container->get(SamplerExecutor::class . '.composite');
        self::assertInstanceOf(CompositeExecutor::class, $executor);
        $results = $executor->execute(
            new ExecutionContext(ExecutorFixtureBenchmark::class, '', 'passes'),
            new Config('test', []),
        );

        $result = $results->byType(SamplerResult::class)->first();
        self::assertInstanceOf(SamplerResult::class, $result);
        self::assertSame(
            ['page_faults_raw', 'page_faults', 'cpu_time_raw', 'cpu_time'],
            array_keys($result->values),
        );
    }

    public function testRegistersExecutorUnderPerfidiousLinuxTag(): void
    {
        $container = $this->makeContainer();
        $tagged = $container->getServiceIdsForTag(RunnerExtension::TAG_EXECUTOR);

        $this->assertArrayHasKey(LinuxExecutor::class . '.composite', $tagged);
        $this->assertSame('perfidious-linux', $tagged[LinuxExecutor::class . '.composite']['name']);

        $executor = $container->get(LinuxExecutor::class . '.composite');
        $this->assertInstanceOf(CompositeExecutor::class, $executor);
    }

    public function testRegistersProgressLoggerUnderPerfidiousLinuxTag(): void
    {
        $container = $this->makeContainer();
        $tagged = $container->getServiceIdsForTag(RunnerExtension::TAG_PROGRESS_LOGGER);

        $this->assertArrayHasKey(PerfidiousProgressLogger::class, $tagged);
        $this->assertSame('perfidious-linux', $tagged[PerfidiousProgressLogger::class]['name']);

        $logger = $container->get(PerfidiousProgressLogger::class);
        $this->assertInstanceOf(PerfidiousProgressLogger::class, $logger);
    }

    public function testRegistersReportGeneratorUnderPerfidiousTag(): void
    {
        $container = $this->makeContainer();
        $tagged = $container->getServiceIdsForTag(ReportExtension::TAG_REPORT_GENERATOR);

        $this->assertArrayHasKey(PerfidiousGenerator::class, $tagged);
        $this->assertSame('perfidious', $tagged[PerfidiousGenerator::class]['name']);

        $generator = $container->get(PerfidiousGenerator::class);
        $this->assertInstanceOf(PerfidiousGenerator::class, $generator);
    }

    public function testCustomMetricsParameterReachesTheExecutor(): void
    {
        $container = $this->makeContainer();

        // Constructing the service exercises PerfidiousExtension's parameter
        // plumbing end to end: it opens a real perf handle for the configured
        // metric, so a bad wiring (wrong parameter name, type mismatch, etc.)
        // would throw here rather than silently doing nothing.
        $executor = $container->get(LinuxExecutor::class);

        $this->assertInstanceOf(LinuxExecutor::class, $executor);
    }

    public function testRegistersRemoteExecutorUnderPerfidiousLinuxRemoteTag(): void
    {
        $container = $this->makeContainer();
        $tagged = $container->getServiceIdsForTag(RunnerExtension::TAG_EXECUTOR);

        $this->assertArrayHasKey(LinuxRemoteExecutor::class . '.composite', $tagged);
        $this->assertSame('perfidious-linux-remote', $tagged[LinuxRemoteExecutor::class . '.composite']['name']);

        $executor = $container->get(LinuxRemoteExecutor::class . '.composite');
        $this->assertInstanceOf(CompositeExecutor::class, $executor);

        // Regression test for the bug in the abandoned topic/remote-executor attempt:
        // the method executor (which runs @BeforeClassMethods/@AfterClassMethods) must
        // be RemoteMethodExecutor, not LocalMethodExecutor -- since every iteration runs
        // in a freshly spawned subprocess, class-level hooks need to run on the same
        // execution model, not in the long-lived CLI process.
        $methodExecutorProperty = new ReflectionProperty(CompositeExecutor::class, 'methodExecutor');
        $decorator = $methodExecutorProperty->getValue($executor);
        $this->assertInstanceOf(ErrorHandlingExecutorDecorator::class, $decorator);

        $innerExecutorProperty = new ReflectionProperty(ErrorHandlingExecutorDecorator::class, 'executor');
        $inner = $innerExecutorProperty->getValue($decorator);
        $this->assertInstanceOf(RemoteMethodExecutor::class, $inner);
    }

    public function testCustomMetricsParameterReachesTheRemoteExecutor(): void
    {
        $container = $this->makeContainer();

        // Unlike LinuxExecutor, constructing LinuxRemoteExecutor doesn't
        // eagerly open a perf handle (that only happens per-execution, in the child
        // process), so this has to actually run once to prove the wiring works.
        $executor = $container->get(LinuxRemoteExecutor::class);
        $this->assertInstanceOf(LinuxRemoteExecutor::class, $executor);

        $resolver = new OptionsResolver();
        $executor->configure($resolver);
        $resolved = $resolver->resolve([]);
        $config = [];
        foreach ($resolved as $key => $value) {
            assert(is_string($key));
            $config[$key] = $value;
        }

        $context = new ExecutionContext(
            className: ExecutorFixtureBenchmark::class,
            classPath: (string) (new ReflectionClass(ExecutorFixtureBenchmark::class))->getFileName(),
            methodName: 'passes',
            revolutions: 5,
        );

        $results = $executor->execute($context, new Config('test', $config));
        $result = $results->byType(PerfidiousResult::class)->first();

        $this->assertInstanceOf(PerfidiousResult::class, $result);
        $this->assertArrayHasKey('perf__PERF_COUNT_SW_CPU_CLOCK_raw', $result->values);
    }
}
