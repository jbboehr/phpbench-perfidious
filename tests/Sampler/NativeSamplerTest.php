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

namespace jbboehr\PhpBenchPerfidious\Tests\Sampler;

use jbboehr\PhpBenchPerfidious\Sampler\NativeSampler;
use Perfidious\Metric;
use Perfidious\Sampler as PerfidiousSampler;
use Perfidious\Scope;
use Perfidious\UnsupportedMetricException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class NativeSamplerTest extends TestCase
{
    public function testRejectsAnEmptyMetricList(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('At least one sampler metric is required');

        new NativeSampler([]);
    }

    public function testRejectsAnUnknownMetricName(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('not-a-metric');

        new NativeSampler(['not-a-metric']);
    }

    /** @param list<string> $metrics */
    #[DataProvider('duplicateMetricLists')]
    public function testRejectsDuplicateMetricsInTheConstructor(array $metrics): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('duplicate');

        new NativeSampler($metrics);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function duplicateMetricLists(): iterable
    {
        yield 'adjacent' => [['cpu-time', 'cpu-time']];
        yield 'separated' => [['cpu-time', 'page-faults', 'cpu-time']];
    }

    public function testIgnoresTheOperationReturnValue(): void
    {
        $measurement = (new NativeSampler(['cpu-time']))->measure(
            static fn (): string => hash('sha256', 'payload'),
        );

        self::assertSame(['cpu-time'], array_keys($measurement->values));
        self::assertGreaterThanOrEqual(0, $measurement->values['cpu-time']);
        self::assertGreaterThan(0, $measurement->elapsedTimeNs);
    }

    public function testMeasuresAnInvokableExactlyOnceWithDefaultThreadScope(): void
    {
        $operation = new class () {
            public int $calls = 0;
            public float $sum = 0;

            public function __invoke(): void
            {
                ++$this->calls;
                for ($i = 0; $i < 100_000; ++$i) {
                    $this->sum += sqrt($i);
                }
            }
        };

        $measurement = (new NativeSampler(['cpu-time']))->measure($operation);

        self::assertSame(1, $operation->calls);
        self::assertGreaterThan(0, $operation->sum);
        self::assertSame(['cpu-time'], array_keys($measurement->values));
        self::assertGreaterThan(0, $measurement->values['cpu-time']);
        self::assertGreaterThan(0, $measurement->elapsedTimeNs);
    }

    #[RequiresOperatingSystem('Linux')]
    public function testPreservesRequestedMetricNamesAndOrder(): void
    {
        $measurement = (new NativeSampler(
            ['page-faults', 'cpu-time'],
            Scope::CurrentThread,
        ))->measure(static function (): void {
            $payload = str_repeat('x', 1_000_000);
            self::assertSame(1_000_000, strlen($payload));
        });

        self::assertSame(['page-faults', 'cpu-time'], array_keys($measurement->values));
        self::assertGreaterThanOrEqual(0, $measurement->values['page-faults']);
        self::assertGreaterThan(0, $measurement->values['cpu-time']);
    }

    public function testRepeatedMeasurementsExcludeWorkBetweenCalls(): void
    {
        $sampler = new NativeSampler(['cpu-time']);
        $sampler->measure(static function (): void {
            usleep(20_000);
        });
        usleep(20_000);

        $start = hrtime(true);
        $measurement = $sampler->measure(static function (): void {
            $sum = 0;
            for ($i = 0; $i < 10_000; ++$i) {
                $sum += sqrt($i);
            }
            self::assertGreaterThan(0, $sum);
        });
        $elapsed = hrtime(true) - $start;

        self::assertGreaterThan(0, $measurement->values['cpu-time']);
        self::assertGreaterThanOrEqual(0, $measurement->elapsedTimeNs);
        self::assertLessThanOrEqual($elapsed, $measurement->elapsedTimeNs);
    }

    public function testNestedMeasurementsOnTheSameAdapterHaveIndependentIntervals(): void
    {
        $sampler = new NativeSampler(['cpu-time']);
        $outerCalls = 0;
        $innerCalls = 0;
        $innerMeasurement = null;

        $outerMeasurement = $sampler->measure(
            static function () use ($sampler, &$outerCalls, &$innerCalls, &$innerMeasurement): void {
                ++$outerCalls;
                $innerMeasurement = $sampler->measure(static function () use (&$innerCalls): void {
                    ++$innerCalls;
                    self::consumeCpu();
                });
            },
        );

        self::assertSame(1, $outerCalls);
        self::assertSame(1, $innerCalls);
        self::assertNotNull($innerMeasurement);
        self::assertGreaterThan(0, $innerMeasurement->elapsedTimeNs);
        self::assertGreaterThan(0, $innerMeasurement->values['cpu-time']);
        self::assertGreaterThanOrEqual($innerMeasurement->elapsedTimeNs, $outerMeasurement->elapsedTimeNs);
        self::assertGreaterThanOrEqual(
            $innerMeasurement->values['cpu-time'],
            $outerMeasurement->values['cpu-time'],
        );
    }

    public function testReturnsNativeNanosecondScaleWithoutNormalization(): void
    {
        $nativeSampler = PerfidiousSampler::open([Metric::CpuTime]);
        $innerInterval = null;

        try {
            $before = $nativeSampler->read();
            $measurement = (new NativeSampler(['cpu-time']))->measure(
                static function () use (&$innerInterval): void {
                    $innerSampler = PerfidiousSampler::open([Metric::CpuTime]);
                    try {
                        $before = $innerSampler->read();
                        self::consumeCpu();
                        $innerInterval = $innerSampler->read()->since($before);
                    } finally {
                        $innerSampler->close();
                    }
                },
            );
            $nativeEnvelope = $nativeSampler->read()->since($before);
        } finally {
            $nativeSampler->close();
        }

        self::assertNotNull($innerInterval);
        self::assertGreaterThan(0, $measurement->values['cpu-time']);
        self::assertLessThanOrEqual($nativeEnvelope->value(Metric::CpuTime), $measurement->values['cpu-time']);
        self::assertGreaterThanOrEqual($innerInterval->value(Metric::CpuTime), $measurement->values['cpu-time']);
        self::assertLessThanOrEqual($nativeEnvelope->elapsedTimeNs, $measurement->elapsedTimeNs);
        self::assertGreaterThanOrEqual($innerInterval->elapsedTimeNs, $measurement->elapsedTimeNs);
    }

    #[RequiresOperatingSystem('Linux')]
    public function testDoesNotRetainNativeResourcesAfterSuccessOrFailure(): void
    {
        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('/proc/self/fd is required to observe native resource lifetime');
        }

        $sampler = new NativeSampler(['cpu-time']);
        $before = self::fileDescriptorCount();

        $sampler->measure(static function (): void {
        });
        self::assertSame($before, self::fileDescriptorCount());

        try {
            $sampler->measure(static function (): void {
                throw new \RuntimeException('expected failure');
            });
            self::fail('Expected the operation to throw');
        } catch (\RuntimeException $error) {
            self::assertSame('expected failure', $error->getMessage());
        }
        self::assertSame($before, self::fileDescriptorCount());
    }

    #[DataProvider('operationErrors')]
    public function testPreservesOperationErrorsAndCanMeasureAgain(\Throwable $error): void
    {
        $sampler = new NativeSampler(['cpu-time']);
        try {
            $sampler->measure(static function () use ($error): void {
                throw $error;
            });
            self::fail('Expected the operation to throw');
        } catch (\Throwable $caught) {
            self::assertSame($error, $caught);
        }

        $called = false;
        $measurement = $sampler->measure(static function () use (&$called): void {
            $called = true;
        });
        self::assertTrue($called);
        self::assertSame(['cpu-time'], array_keys($measurement->values));
        self::assertGreaterThanOrEqual(0, $measurement->values['cpu-time']);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function operationErrors(): iterable
    {
        yield 'exception' => [new \RuntimeException('operation failed')];
        yield 'error' => [new \Error('operation failed')];
    }

    #[RequiresOperatingSystem('Linux')]
    public function testUnsupportedScopeIsCheckedWhenMeasuringAndPreservesNativeError(): void
    {
        $sampler = new NativeSampler(['cpu-time'], Scope::CurrentProcess);
        $called = false;

        try {
            $sampler->measure(static function () use (&$called): void {
                $called = true;
            });
            self::fail('Expected Linux to reject process-wide sampling');
        } catch (UnsupportedMetricException $error) {
            self::assertSame(Scope::CurrentProcess, $error->scope);
            self::assertSame([Metric::CpuTime], $error->unsupportedMetrics);
        }
        self::assertFalse($called);
    }

    public function testMissingSamplerApiHasATargetedDiagnostic(): void
    {
        $script = sprintf(
            'require %s; new jbboehr\\PhpBenchPerfidious\\Sampler\\NativeSampler(["cpu-time"]);',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
        );
        $process = new Process([PHP_BINARY, '-n', '-d', 'display_errors=stderr', '-r', $script]);
        $process->run();

        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString(
            'The perfidious sampler API is required; install and enable ext-perfidious 0.3.1 or newer',
            $process->getErrorOutput() . $process->getOutput(),
        );
    }

    private static function consumeCpu(): void
    {
        $deadline = hrtime(true) + 20_000_000;
        $sum = 0.0;
        $operand = 1;
        do {
            $sum += sqrt($operand++);
        } while (hrtime(true) < $deadline);

        self::assertGreaterThan(0, $sum);
    }

    private static function fileDescriptorCount(): int
    {
        $entries = scandir('/proc/self/fd');
        if ($entries === false) {
            self::fail('Unable to inspect /proc/self/fd');
        }

        return count($entries);
    }
}
