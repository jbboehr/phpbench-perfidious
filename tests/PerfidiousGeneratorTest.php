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

use DateTime;
use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use jbboehr\PhpBenchPerfidious\Report\PerfidiousGenerator;
use jbboehr\PhpBenchPerfidious\SamplerResult;
use PhpBench\Expression\Ast\PhpValue;
use PhpBench\Model\ParameterSet;
use PhpBench\Model\Suite;
use PhpBench\Model\SuiteCollection;
use PhpBench\Registry\Config;
use PhpBench\Report\Model\Table;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PerfidiousGeneratorTest extends TestCase
{
    private function cellValue(Table $table, int $rowIndex, string $key): mixed
    {
        $cell = $table->rows()[$rowIndex]->cells()[$key];
        $this->assertInstanceOf(PhpValue::class, $cell);

        return $cell->value();
    }

    public function testConfigureSetsMeaningfulDefaultTitleAndDescription(): void
    {
        $generator = new PerfidiousGenerator();
        $resolver = new OptionsResolver();
        $generator->configure($resolver);

        $resolved = $resolver->resolve();

        $this->assertSame('Perfidious report', $resolved['title']);
        $this->assertIsString($resolved['description']);
        $this->assertNotSame('', $resolved['description']);
    }

    public function testConfigureRejectsNonStringMetadata(): void
    {
        $generator = new PerfidiousGenerator();
        $resolver = new OptionsResolver();
        $generator->configure($resolver);

        $this->expectException(InvalidOptionsException::class);

        $resolver->resolve(['title' => ['not a string']]);
    }

    public function testGenerateProducesOneRowPerIterationWithoutRawColumns(): void
    {
        $suite = new Suite(null, new DateTime());
        $benchmark = $suite->createBenchmark(self::class);
        $subject = $benchmark->createSubject('bench');
        $variant = $subject->createVariant(ParameterSet::fromUnserializedValues('default', []), 10, 0);

        foreach ([5000, 6000] as $instructions) {
            $variant->createIteration([
                PerfidiousResult::create(
                    timeRunning: 1000,
                    timeEnabled: 1000,
                    revolutions: 10,
                    rawValues: ['perf::PERF_COUNT_HW_INSTRUCTIONS' => $instructions],
                ),
            ]);
        }

        $reports = (new PerfidiousGenerator())->generate(
            new SuiteCollection([$suite]),
            new Config('test', []),
        );

        $report = $reports->first();
        $this->assertSame('Perfidious report', $report->title());
        $this->assertSame('Per-iteration sampler and Linux performance counter results.', $report->description());

        $tables = $report->tables();
        $this->assertCount(1, $tables);

        $table = array_values($tables)[0];

        $rows = $table->rows();
        $this->assertCount(2, $rows);

        $first = $rows[0]->cells();
        $this->assertSame(0, $this->cellValue($table, 0, 'iter'));
        $this->assertSame($benchmark->getName(), $this->cellValue($table, 0, 'benchmark'));
        $this->assertSame('bench', $this->cellValue($table, 0, 'subject'));
        $this->assertSame('default', $this->cellValue($table, 0, 'parameter_set'));
        $this->assertSame(10, $this->cellValue($table, 0, 'revs'));
        $this->assertArrayHasKey('perf__PERF_COUNT_HW_INSTRUCTIONS', $first);
        $this->assertArrayNotHasKey('perf__PERF_COUNT_HW_INSTRUCTIONS_raw', $first);

        $this->assertSame(1, $this->cellValue($table, 1, 'iter'));
    }

    public function testGenerateIdentifiesRowsByParameterSet(): void
    {
        $suite = new Suite(null, new DateTime());
        $benchmark = $suite->createBenchmark(self::class);
        $subject = $benchmark->createSubject('bench');

        foreach (['small' => 10, 'large' => 100] as $parameterSetName => $instructions) {
            $variant = $subject->createVariant(
                ParameterSet::fromUnserializedValues($parameterSetName, ['size' => $instructions]),
                10,
                0,
            );
            $variant->createIteration([
                PerfidiousResult::create(
                    timeRunning: 1000,
                    timeEnabled: 1000,
                    revolutions: 10,
                    rawValues: ['perf::PERF_COUNT_HW_INSTRUCTIONS' => $instructions],
                ),
            ]);
        }

        $report = (new PerfidiousGenerator())->generate(
            new SuiteCollection([$suite]),
            new Config('test', []),
        )->first();
        $table = array_values($report->tables())[0];

        $this->assertSame('small', $this->cellValue($table, 0, 'parameter_set'));
        $this->assertSame('large', $this->cellValue($table, 1, 'parameter_set'));
    }

    public function testGenerateSupportsSamplerResults(): void
    {
        $suite = new Suite(null, new DateTime());
        $benchmark = $suite->createBenchmark(self::class);
        $subject = $benchmark->createSubject('benchSampler');
        $variant = $subject->createVariant(ParameterSet::fromUnserializedValues('small', []), 10, 0);
        foreach ([505, 606] as $cpuTime) {
            $variant->createIteration([
                SamplerResult::create(10_000, 10, ['cpu-time' => $cpuTime, 'page-faults' => 0]),
            ]);
        }

        $report = (new PerfidiousGenerator())->generate(
            new SuiteCollection([$suite]),
            new Config('test', []),
        )->first();
        self::assertCount(1, $report->tables());
        $table = array_values($report->tables())[0];
        self::assertCount(2, $table->rows());
        self::assertSame([
            'iter', 'benchmark', 'subject', 'parameter_set', 'revs', 'cpu_time', 'page_faults',
        ], $table->columnNames());
        self::assertSame($benchmark->getName(), $this->cellValue($table, 0, 'benchmark'));
        self::assertSame('benchSampler', $this->cellValue($table, 0, 'subject'));
        self::assertSame('small', $this->cellValue($table, 0, 'parameter_set'));
        self::assertSame(10, $this->cellValue($table, 0, 'revs'));
        self::assertSame(0, $this->cellValue($table, 0, 'iter'));
        self::assertSame(1, $this->cellValue($table, 1, 'iter'));
        self::assertSame(50.5, $this->cellValue($table, 0, 'cpu_time'));
        self::assertSame(60.6, $this->cellValue($table, 1, 'cpu_time'));
        self::assertSame(0, $this->cellValue($table, 0, 'page_faults'));
    }

    /** @return iterable<string, array{bool}> */
    public static function mixedResultOrder(): iterable
    {
        yield 'sampler first' => [false];
        yield 'Linux first' => [true];
    }

    #[DataProvider('mixedResultOrder')]
    public function testGenerateAlignsMixedMetricColumns(bool $linuxFirst): void
    {
        $suite = new Suite(null, new DateTime());
        $benchmark = $suite->createBenchmark(self::class);
        $results = [
            'sampler' => SamplerResult::create(10_000, 10, ['cpu-time' => 505, 'page-faults' => 0]),
            'otherSampler' => SamplerResult::create(20_000, 10, ['page-faults' => 20, 'instructions' => 255]),
            'linux' => PerfidiousResult::create(1000, 1000, 10, ['perf::PERF_COUNT_HW_INSTRUCTIONS' => 105]),
        ];
        if ($linuxFirst) {
            $results = array_reverse($results, true);
        }
        foreach ($results as $name => $result) {
            $benchmark->createSubject($name)
                ->createVariant(ParameterSet::fromUnserializedValues('default', []), 10, 0)
                ->createIteration([$result]);
        }

        $report = (new PerfidiousGenerator())->generate(
            new SuiteCollection([$suite]),
            new Config('test', []),
        )->first();
        $table = array_values($report->tables())[0];
        $metrics = $linuxFirst
            ? ['perf__PERF_COUNT_HW_INSTRUCTIONS', 'page_faults', 'instructions', 'cpu_time']
            : ['cpu_time', 'page_faults', 'instructions', 'perf__PERF_COUNT_HW_INSTRUCTIONS'];
        self::assertSame([
            'iter', 'benchmark', 'subject', 'parameter_set', 'revs', ...$metrics,
        ], $table->columnNames());
        self::assertCount(3, $table->rows());
        foreach ($table->rows() as $index => $row) {
            self::assertSame($table->columnNames(), $row->keys());
            $name = $this->cellValue($table, $index, 'subject');
            self::assertSame('sampler' === $name ? 50.5 : null, $this->cellValue($table, $index, 'cpu_time'));
            self::assertSame(match ($name) {
                'sampler' => 0,
                'otherSampler' => 2,
                default => null,
            }, $this->cellValue($table, $index, 'page_faults'));
            self::assertSame('otherSampler' === $name ? 25.5 : null, $this->cellValue($table, $index, 'instructions'));
            self::assertSame('linux' === $name ? 10.5 : null, $this->cellValue($table, $index, 'perf__PERF_COUNT_HW_INSTRUCTIONS'));
        }
    }

    public function testGenerateKeepsMetricColumnsWithinEachSuite(): void
    {
        $suites = [];
        foreach ([
            ['sampler', SamplerResult::create(10_000, 10, ['cpu-time' => 505])],
            ['linux', PerfidiousResult::create(1000, 1000, 10, ['perf::PERF_COUNT_HW_INSTRUCTIONS' => 105])],
        ] as [$name, $result]) {
            $suite = new Suite(null, new DateTime());
            $suite->createBenchmark(self::class)
                ->createSubject($name)
                ->createVariant(ParameterSet::fromUnserializedValues('default', []), 10, 0)
                ->createIteration([$result]);
            $suites[] = $suite;
        }

        $report = (new PerfidiousGenerator())->generate(
            new SuiteCollection($suites),
            new Config('test', []),
        )->first();
        $tables = array_values($report->tables());

        self::assertCount(2, $tables);
        self::assertSame([
            'iter', 'benchmark', 'subject', 'parameter_set', 'revs', 'cpu_time',
        ], $tables[0]->columnNames());
        self::assertSame([
            'iter', 'benchmark', 'subject', 'parameter_set', 'revs', 'perf__PERF_COUNT_HW_INSTRUCTIONS',
        ], $tables[1]->columnNames());
        self::assertSame(50.5, $this->cellValue($tables[0], 0, 'cpu_time'));
        self::assertSame(10.5, $this->cellValue($tables[1], 0, 'perf__PERF_COUNT_HW_INSTRUCTIONS'));
    }

    public function testGenerateUsesConfiguredTitleAndDescription(): void
    {
        $report = (new PerfidiousGenerator())->generate(
            new SuiteCollection([]),
            new Config('test', [
                'title' => 'Custom title',
                'description' => 'Custom description',
            ]),
        )->first();

        $this->assertSame('Custom title', $report->title());
        $this->assertSame('Custom description', $report->description());
    }
}
