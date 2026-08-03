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
use PhpBench\Expression\Ast\PhpValue;
use PhpBench\Model\ParameterSet;
use PhpBench\Model\Suite;
use PhpBench\Model\SuiteCollection;
use PhpBench\Registry\Config;
use PhpBench\Report\Model\Table;
use PHPUnit\Framework\TestCase;
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
        $this->assertSame('Per-iteration hardware/software performance counter results.', $report->description());

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
