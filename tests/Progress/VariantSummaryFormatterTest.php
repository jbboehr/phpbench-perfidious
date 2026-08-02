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

namespace jbboehr\PhpBenchPerfidious\Tests\Progress;

use DateTime;
use jbboehr\PhpBenchPerfidious\PerfidiousExtension;
use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use jbboehr\PhpBenchPerfidious\Progress\VariantSummaryFormatter;
use PhpBench\Assertion\ParameterProvider;
use PhpBench\DependencyInjection\Container;
use PhpBench\Expression\ExpressionLanguage;
use PhpBench\Expression\Printer\EvaluatingPrinter;
use PhpBench\Extension\ConsoleExtension;
use PhpBench\Extension\CoreExtension;
use PhpBench\Extension\ExpressionExtension;
use PhpBench\Extension\ReportExtension;
use PhpBench\Extension\RunnerExtension;
use PhpBench\Extension\StorageExtension;
use PhpBench\Extensions\XDebug\XDebugExtension;
use PhpBench\Model\Benchmark;
use PhpBench\Model\ParameterSet;
use PhpBench\Model\Result\TimeResult;
use PhpBench\Model\Subject;
use PhpBench\Model\Suite;
use PhpBench\Model\Variant;
use PHPUnit\Framework\TestCase;

class VariantSummaryFormatterTest extends TestCase
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
        ]);
        $container->init();

        return $container;
    }

    private function makeFormatter(): VariantSummaryFormatter
    {
        $formatter = $this->makeContainer()->get(VariantSummaryFormatter::class);
        $this->assertInstanceOf(VariantSummaryFormatter::class, $formatter);

        return $formatter;
    }

    private function makeVariant(): Variant
    {
        $suite = new Suite(null, new DateTime());
        $benchmark = new Benchmark($suite, self::class);
        $subject = new Subject($benchmark, 'bench');
        $variant = new Variant($subject, ParameterSet::fromUnserializedValues('default', []), 10, 0);

        foreach ([1000, 1200, 900] as $instructions) {
            $variant->createIteration([
                new TimeResult(1000, 10),
                PerfidiousResult::create(
                    timeRunning: 1000,
                    timeEnabled: 1000,
                    revolutions: 10,
                    rawValues: ['perf::PERF_COUNT_HW_INSTRUCTIONS' => $instructions],
                ),
            ]);
        }

        $variant->computeStats();

        return $variant;
    }

    public function testFormatVariantIncludesInstructionsSummary(): void
    {
        $output = $this->makeFormatter()->formatVariant($this->makeVariant());

        $this->assertStringContainsString('Instr', $output);
    }

    public function testFormatVariantDoesNotReferenceUnsanitizedEventName(): void
    {
        // DEFAULT_FORMAT/BASELINE_FORMAT reference the sanitized event key
        // (perf__PERF_COUNT_HW_INSTRUCTIONS); if PerfidiousResult's sanitization
        // ever changes, the expression would fail to resolve the metric.
        $output = $this->makeFormatter()->formatVariant($this->makeVariant());

        $this->assertStringNotContainsString('perf::PERF_COUNT_HW_INSTRUCTIONS', $output);
    }

    public function testConstructorUsesGivenFormatInsteadOfDefault(): void
    {
        $container = $this->makeContainer();

        $parser = $container->get(ExpressionLanguage::class);
        $printer = $container->get(EvaluatingPrinter::class);
        $paramProvider = $container->get(ParameterProvider::class);
        $this->assertInstanceOf(ExpressionLanguage::class, $parser);
        $this->assertInstanceOf(EvaluatingPrinter::class, $printer);
        $this->assertInstanceOf(ParameterProvider::class, $paramProvider);

        $formatter = new VariantSummaryFormatter(
            $parser,
            $printer,
            $paramProvider,
            format: '"custom-format-marker"',
        );

        $this->assertSame('custom-format-marker', $formatter->formatVariant($this->makeVariant()));
    }
}
