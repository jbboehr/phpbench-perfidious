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

use jbboehr\PhpBenchPerfidious\Executor\PerfidiousRemoteExecutor;
use jbboehr\PhpBenchPerfidious\PerfidiousExecutor;
use jbboehr\PhpBenchPerfidious\PerfidiousExtension;
use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use jbboehr\PhpBenchPerfidious\Progress\PerfidiousProgressLogger;
use jbboehr\PhpBenchPerfidious\Report\PerfidiousGenerator;
use jbboehr\PhpBenchPerfidious\Tests\Fixtures\ExecutorFixtureBenchmark;
use PhpBench\DependencyInjection\Container;
use PhpBench\Executor\CompositeExecutor;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\Method\ErrorHandlingExecutorDecorator;
use PhpBench\Executor\Method\RemoteMethodExecutor;
use PhpBench\Extension\ConsoleExtension;
use PhpBench\Extension\CoreExtension;
use PhpBench\Extension\ExpressionExtension;
use PhpBench\Extension\ReportExtension;
use PhpBench\Extension\RunnerExtension;
use PhpBench\Extension\StorageExtension;
use PhpBench\Extensions\XDebug\XDebugExtension;
use PhpBench\Registry\Config;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PerfidiousExtensionTest extends TestCase
{
    private function makeContainer(): Container
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
        ], [
            // Software-only metric: reliable in sandboxed/virtualized CI environments
            // where hardware PMU counters are not available.
            PerfidiousExtension::PARAM_PERFIDIOUS_METRICS => ['perf::PERF_COUNT_SW_CPU_CLOCK'],
            // Needed for PerfidiousRemoteExecutor's child process to autoload fixture classes.
            RunnerExtension::PARAM_BOOTSTRAP => __DIR__ . '/bootstrap.php',
        ]);
        $container->init();

        return $container;
    }

    public function testConfigureSetsDefaultParameters(): void
    {
        $container = new Container([PerfidiousExtension::class]);
        $container->init();

        $this->assertSame(
            PerfidiousExecutor::DEFAULT_METRICS,
            $container->getParameter(PerfidiousExtension::PARAM_PERFIDIOUS_METRICS)
        );
        $this->assertIsString($container->getParameter(PerfidiousExtension::PARAM_PROGRESS_SUMMARY_FORMAT));
        $this->assertIsString($container->getParameter(PerfidiousExtension::PARAM_PROGRESS_SUMMARY_BASELINE_FORMAT));
    }

    public function testRegistersExecutorUnderPerfidiousTag(): void
    {
        $container = $this->makeContainer();
        $tagged = $container->getServiceIdsForTag(RunnerExtension::TAG_EXECUTOR);

        $this->assertArrayHasKey(PerfidiousExecutor::class . '.composite', $tagged);
        $this->assertSame('perfidious', $tagged[PerfidiousExecutor::class . '.composite']['name']);

        $executor = $container->get(PerfidiousExecutor::class . '.composite');
        $this->assertInstanceOf(CompositeExecutor::class, $executor);
    }

    public function testRegistersProgressLoggerUnderPerfidiousTag(): void
    {
        $container = $this->makeContainer();
        $tagged = $container->getServiceIdsForTag(RunnerExtension::TAG_PROGRESS_LOGGER);

        $this->assertArrayHasKey(PerfidiousProgressLogger::class, $tagged);
        $this->assertSame('perfidious', $tagged[PerfidiousProgressLogger::class]['name']);

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
        $executor = $container->get(PerfidiousExecutor::class);

        $this->assertInstanceOf(PerfidiousExecutor::class, $executor);
    }

    public function testRegistersRemoteExecutorUnderPerfidiousRemoteTag(): void
    {
        $container = $this->makeContainer();
        $tagged = $container->getServiceIdsForTag(RunnerExtension::TAG_EXECUTOR);

        $this->assertArrayHasKey(PerfidiousRemoteExecutor::class . '.composite', $tagged);
        $this->assertSame('perfidious-remote', $tagged[PerfidiousRemoteExecutor::class . '.composite']['name']);

        $executor = $container->get(PerfidiousRemoteExecutor::class . '.composite');
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

        // Unlike PerfidiousExecutor, constructing PerfidiousRemoteExecutor doesn't
        // eagerly open a perf handle (that only happens per-execution, in the child
        // process), so this has to actually run once to prove the wiring works.
        $executor = $container->get(PerfidiousRemoteExecutor::class);
        $this->assertInstanceOf(PerfidiousRemoteExecutor::class, $executor);

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
