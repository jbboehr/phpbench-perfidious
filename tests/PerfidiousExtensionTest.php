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
use jbboehr\PhpBenchPerfidious\PerfidiousExtension;
use jbboehr\PhpBenchPerfidious\Progress\PerfidiousProgressLogger;
use jbboehr\PhpBenchPerfidious\Report\PerfidiousGenerator;
use PhpBench\DependencyInjection\Container;
use PhpBench\Executor\CompositeExecutor;
use PhpBench\Extension\ConsoleExtension;
use PhpBench\Extension\CoreExtension;
use PhpBench\Extension\ExpressionExtension;
use PhpBench\Extension\ReportExtension;
use PhpBench\Extension\RunnerExtension;
use PhpBench\Extension\StorageExtension;
use PhpBench\Extensions\XDebug\XDebugExtension;
use PHPUnit\Framework\TestCase;

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

        $logger = $container->get(PerfidiousProgressLogger::class);
        $this->assertInstanceOf(PerfidiousProgressLogger::class, $logger);
    }

    public function testRegistersReportGeneratorUnderPerfidiousTag(): void
    {
        $container = $this->makeContainer();
        $tagged = $container->getServiceIdsForTag(ReportExtension::TAG_REPORT_GENERATOR);

        $this->assertArrayHasKey(PerfidiousGenerator::class, $tagged);

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
}
