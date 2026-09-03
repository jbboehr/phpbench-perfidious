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

use InvalidArgumentException;
use jbboehr\PhpBenchPerfidious\SamplerResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SamplerResultTest extends TestCase
{
    public function testCreatePreservesRawDeltasAndNormalizesValuesPerRevolution(): void
    {
        $result = SamplerResult::create(
            elapsedTimeNs: 9_000_000,
            revolutions: 4,
            rawValues: [
                'cpu-time' => 12_000,
                'page-faults' => 6,
            ],
        );

        self::assertSame(9_000_000, $result->elapsedTimeNs);
        self::assertSame(4, $result->revolutions);
        self::assertSame([
            'cpu_time_raw' => 12_000,
            'cpu_time' => 3_000,
            'page_faults_raw' => 6,
            'page_faults' => 1.5,
        ], $result->values);
        self::assertSame([
            'elapsedTimeNs' => 9_000_000,
            'revolutions' => 4,
            'cpu_time_raw' => 12_000,
            'cpu_time' => 3_000,
            'page_faults_raw' => 6,
            'page_faults' => 1.5,
        ], $result->getMetrics());
        self::assertSame('perfidious_sampler', $result->getKey());
    }

    public function testCreateAcceptsZeroAtEveryNonnegativeBoundary(): void
    {
        $result = SamplerResult::create(
            elapsedTimeNs: 0,
            revolutions: 1,
            rawValues: ['context-switches' => 0],
        );

        self::assertSame(0, $result->elapsedTimeNs);
        self::assertSame(1, $result->revolutions);
        self::assertSame(0, $result->values['context_switches_raw']);
        self::assertEquals(0, $result->values['context_switches']);
    }

    public function testFromArrayRestoresNumericStringsFromXmlStorage(): void
    {
        $result = SamplerResult::fromArray([
            'elapsedTimeNs' => '9000000',
            'revolutions' => '4',
            'cpu_time_raw' => '12000',
            'cpu_time' => '3000',
            'page_faults' => '1.5',
            'cache_miss_rate' => '1.0E-5',
        ]);

        self::assertSame(9_000_000, $result->elapsedTimeNs);
        self::assertSame(4, $result->revolutions);
        self::assertSame([
            'cpu_time_raw' => 12_000,
            'cpu_time' => 3_000,
            'page_faults' => 1.5,
            'cache_miss_rate' => 1.0E-5,
        ], $result->values);
    }

    public function testFromArrayAcceptsFloatStringsSerializedWithDifferentPrecision(): void
    {
        $originalPrecision = ini_get('precision');

        try {
            ini_set('precision', '17');
            $serializedValue = (string) (1 / 3);
            ini_set('precision', '14');

            $result = SamplerResult::fromArray([
                'elapsedTimeNs' => '1',
                'revolutions' => '1',
                'cpu_time' => $serializedValue,
            ]);
        } finally {
            ini_set('precision', $originalPrecision);
        }

        self::assertSame(1 / 3, $result->values['cpu_time']);
    }

    public function testFromArrayAcceptsZeroInEverySerializedRepresentation(): void
    {
        $result = SamplerResult::fromArray([
            'elapsedTimeNs' => '0',
            'revolutions' => '1',
            'cpu_time_raw' => '0',
            'cpu_time' => '0e-4000',
        ]);

        self::assertSame(0, $result->elapsedTimeNs);
        self::assertSame(0, $result->values['cpu_time_raw']);
        self::assertSame(0.0, $result->values['cpu_time']);
    }

    #[DataProvider('reservedRawSuffixProvider')]
    public function testCreateRejectsMetricNamesEndingInReservedRawSuffix(string $metric): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::create(
            elapsedTimeNs: 10,
            revolutions: 2,
            rawValues: [$metric => 3],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedRawSuffixProvider(): iterable
    {
        yield 'literal suffix' => ['cpu_raw'];
        yield 'suffix after normalization' => ['cpu-raw'];
    }

    public function testCreateRejectsNegativeElapsedTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::create(elapsedTimeNs: -1, revolutions: 1, rawValues: []);
    }

    public function testCreateRejectsNonPositiveRevolutions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::create(elapsedTimeNs: 0, revolutions: 0, rawValues: []);
    }

    public function testCreateValidatesRevolutionsBeforeNormalizingMetrics(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::create(elapsedTimeNs: 0, revolutions: 0, rawValues: ['cpu-time' => 1]);
    }

    public function testCreateRejectsNegativeRawMetricValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::create(elapsedTimeNs: 0, revolutions: 1, rawValues: ['cpu-time' => -1]);
    }

    #[DataProvider('invalidRawMetricValueProvider')]
    public function testCreateRejectsRawMetricValuesThatAreNotIntegers(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $method = new \ReflectionMethod(SamplerResult::class, 'create');
        $method->invoke(null, 1, 1, ['cpu-time' => $value]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidRawMetricValueProvider(): iterable
    {
        yield 'null' => [null];
        yield 'fraction' => [1.5];
        yield 'numeric string' => ['1'];
    }

    public function testCreateRejectsNonStringRawMetricNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $method = new \ReflectionMethod(SamplerResult::class, 'create');
        $method->invoke(null, 1, 1, [0 => 1]);
    }

    public function testCreateRejectsEmptyMetricNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::create(elapsedTimeNs: 0, revolutions: 1, rawValues: ['' => 1]);
    }

    public function testCreateRejectsMetricNamesThatCannotBeSerializedToXml(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::create(elapsedTimeNs: 0, revolutions: 1, rawValues: ['cpu/time' => 1]);
    }

    public function testCreateRejectsGeneratedMetricKeyCollisions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::create(
            elapsedTimeNs: 1,
            revolutions: 1,
            rawValues: ['cpu-time' => 1, 'cpu_time' => 2],
        );
    }

    #[DataProvider('reservedMetadataNameProvider')]
    public function testCreateRejectsMetricKeysReservedForMetadata(string $metric): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::create(
            elapsedTimeNs: 1,
            revolutions: 1,
            rawValues: [$metric => 1],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedMetadataNameProvider(): iterable
    {
        yield 'elapsed time' => ['elapsedTimeNs'];
        yield 'revolutions' => ['revolutions'];
    }

    public function testFromArrayRejectsFractionalMetadataInsteadOfTruncatingIt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sampler result value "revolutions" must be an integer');

        SamplerResult::fromArray([
            'elapsedTimeNs' => 1,
            'revolutions' => '1.5',
        ]);
    }

    #[DataProvider('outOfRangeMetadataProvider')]
    public function testFromArrayRejectsOutOfRangeMetadata(int $elapsedTimeNs, int $revolutions): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::fromArray([
            'elapsedTimeNs' => $elapsedTimeNs,
            'revolutions' => $revolutions,
        ]);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function outOfRangeMetadataProvider(): iterable
    {
        yield 'negative elapsed time' => [-1, 1];
        yield 'zero revolutions' => [0, 0];
    }

    public function testFromArrayRejectsAnOutOfRangeRawCountInsteadOfRoundingItToFloat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::fromArray([
            'elapsedTimeNs' => 1,
            'revolutions' => 1,
            'cpu_time_raw' => '9223372036854775809',
        ]);
    }

    #[DataProvider('nonCanonicalIntegerFieldProvider')]
    public function testFromArrayRejectsNonCanonicalIntegerStrings(string $key, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::fromArray([
            'elapsedTimeNs' => '1',
            'revolutions' => '1',
            $key => $value,
        ]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonCanonicalIntegerFieldProvider(): iterable
    {
        yield 'signed metadata' => ['revolutions', '+1'];
        yield 'signed raw metric' => ['cpu_time_raw', '+1'];
        yield 'empty raw metric' => ['cpu_time_raw', ''];
    }

    #[DataProvider('underflowingMetricProvider')]
    public function testFromArrayRejectsUnderflowingNormalizedMetricStrings(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::fromArray([
            'elapsedTimeNs' => 1,
            'revolutions' => 1,
            'cpu_time' => $value,
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function underflowingMetricProvider(): iterable
    {
        yield 'positive' => ['1e-4000'];
        yield 'negative' => ['-1e-4000'];
    }

    public function testFromArrayRejectsNonNumericMetricValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::fromArray([
            'elapsedTimeNs' => 1,
            'revolutions' => 1,
            'cpu_time' => 'not-a-number',
        ]);
    }

    public function testFromArrayRejectsNegativeMetricValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::fromArray([
            'elapsedTimeNs' => 1,
            'revolutions' => 1,
            'cpu_time' => -1,
        ]);
    }

    #[DataProvider('nonFiniteMetricProvider')]
    public function testFromArrayRejectsNonFiniteMetricValues(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::fromArray([
            'elapsedTimeNs' => 1,
            'revolutions' => 1,
            'cpu_time' => $value,
        ]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonFiniteMetricProvider(): iterable
    {
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
        yield 'overflowing numeric string' => ['1e4000'];
    }

    public function testFromArrayRejectsNonStringMetricNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::fromArray([
            'elapsedTimeNs' => 1,
            'revolutions' => 1,
            0 => 12,
        ]);
    }

    public function testFromArrayRequiresBothMetadataValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SamplerResult::fromArray(['elapsedTimeNs' => 1]);
    }
}
