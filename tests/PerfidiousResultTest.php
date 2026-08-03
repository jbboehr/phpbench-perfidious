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
use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use PHPUnit\Framework\TestCase;

class PerfidiousResultTest extends TestCase
{
    public function testCreateNormalizesValuesAndPreservesRawCounts(): void
    {
        $result = PerfidiousResult::create(
            timeRunning: 1000,
            timeEnabled: 2000,
            revolutions: 10,
            rawValues: ['perf::PERF_COUNT_HW_INSTRUCTIONS' => 500],
        );

        $this->assertSame(500, $result->values['perf__PERF_COUNT_HW_INSTRUCTIONS_raw']);
        $this->assertSame(500 * 2000 / 1000 / 10, $result->values['perf__PERF_COUNT_HW_INSTRUCTIONS']);
    }

    public function testCreateSanitizesEventNames(): void
    {
        $result = PerfidiousResult::create(
            timeRunning: 1,
            timeEnabled: 1,
            revolutions: 1,
            rawValues: ['perf::PERF_COUNT_HW_INSTRUCTIONS:u' => 1],
        );

        $this->assertArrayHasKey('perf__PERF_COUNT_HW_INSTRUCTIONS-u_raw', $result->values);
    }

    public function testCreateRejectsSanitizedEventNameCollisions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Event names "perf/foo" and "perf-foo" both sanitize to metric key "perf-foo"',
        );

        PerfidiousResult::create(
            timeRunning: 1,
            timeEnabled: 1,
            revolutions: 1,
            rawValues: ['perf/foo' => 1, 'perf-foo' => 2],
        );
    }

    public function testCreateRejectsGeneratedRawMetricKeyCollisions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Event names "foo" and "foo_raw" produce conflicting metric key "foo_raw"',
        );

        PerfidiousResult::create(
            timeRunning: 1,
            timeEnabled: 1,
            revolutions: 1,
            rawValues: ['foo' => 1, 'foo_raw' => 2],
        );
    }

    public function testCreateRejectsMetricKeysReservedForResultMetadata(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Event name "timeRunning" produces metric key "timeRunning", which is reserved for result metadata',
        );

        PerfidiousResult::create(
            timeRunning: 1,
            timeEnabled: 1,
            revolutions: 1,
            rawValues: ['timeRunning' => 1],
        );
    }

    public function testCreateRejectsEventNamesThatSanitizeToAnEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Event name "///" produces an empty metric key');

        PerfidiousResult::create(
            timeRunning: 1,
            timeEnabled: 1,
            revolutions: 1,
            rawValues: ['///' => 1],
        );
    }

    public function testConstructorRejectsZeroRevolutions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PerfidiousResult(timeRunning: 1, timeEnabled: 1, revolutions: 0, values: []);
    }

    public function testGetMetricsMergesCoreFieldsAndEventValues(): void
    {
        $result = PerfidiousResult::create(
            timeRunning: 1,
            timeEnabled: 1,
            revolutions: 1,
            rawValues: ['perf::X' => 1],
        );

        $metrics = $result->getMetrics();

        $this->assertSame(1, $metrics['timeRunning']);
        $this->assertSame(1, $metrics['timeEnabled']);
        $this->assertSame(1, $metrics['revolutions']);
        $this->assertArrayHasKey('perf__X', $metrics);
    }

    public function testGetKey(): void
    {
        $result = PerfidiousResult::create(timeRunning: 1, timeEnabled: 1, revolutions: 1, rawValues: []);

        $this->assertSame('perfidious', $result->getKey());
    }

    public function testFromArrayPreservesIntAndFloatTypesAndExcludesCoreKeys(): void
    {
        $result = PerfidiousResult::fromArray([
            'timeRunning' => 1000,
            'timeEnabled' => 2000,
            'revolutions' => 10,
            'perf__X_raw' => 500,
            'perf__X' => 100.5,
        ]);

        $this->assertSame(1000, $result->timeRunning);
        $this->assertSame(2000, $result->timeEnabled);
        $this->assertSame(10, $result->revolutions);

        // assertSame is strict (===), so this also proves the raw counter stayed an
        // int and the normalized rate stayed a float, instead of both being truncated
        // to int as they were before.
        $this->assertSame(500, $result->values['perf__X_raw']);
        $this->assertSame(100.5, $result->values['perf__X']);

        $this->assertArrayNotHasKey('timeRunning', $result->values);
        $this->assertArrayNotHasKey('timeEnabled', $result->values);
        $this->assertArrayNotHasKey('revolutions', $result->values);
    }

    public function testFromArrayHandlesNumericStringValues(): void
    {
        $result = PerfidiousResult::fromArray([
            'timeRunning' => '1000',
            'timeEnabled' => '2000',
            'revolutions' => '10',
            'perf__X_raw' => '500',
            'perf__X' => '100.5',
        ]);

        // assertSame is strict, so this also proves the core fields were actually
        // cast to int rather than left as the numeric strings passed in.
        $this->assertSame(1000, $result->timeRunning);
        $this->assertSame(2000, $result->timeEnabled);
        $this->assertSame(10, $result->revolutions);

        $this->assertSame(500, $result->values['perf__X_raw']);
        $this->assertSame(100.5, $result->values['perf__X']);
    }

    public function testFromArrayThrowsWhenRequiredKeyIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PerfidiousResult::fromArray([]);
    }

    public function testFromArrayThrowsWhenTimeEnabledIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PerfidiousResult::fromArray(['timeRunning' => 1]);
    }

    public function testFromArrayThrowsWhenRevolutionsIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PerfidiousResult::fromArray(['timeRunning' => 1, 'timeEnabled' => 1]);
    }

    public function testFromArrayRejectsNonNumericValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Perfidious result value "perf__X" must be numeric, got string');

        PerfidiousResult::fromArray([
            'timeRunning' => 1,
            'timeEnabled' => 1,
            'revolutions' => 1,
            'perf__X' => 'not-a-number',
        ]);
    }

    public function testFromArrayRejectsInvalidRequiredValueTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Perfidious result value "timeRunning" must be numeric, got array');

        PerfidiousResult::fromArray([
            'timeRunning' => [],
            'timeEnabled' => 1,
            'revolutions' => 1,
        ]);
    }

    public function testCreateTrimsLeadingAndTrailingDashesFromSanitizedEventNames(): void
    {
        // "@foo" -> preg_replace turns the leading "@" into "-foo", which trim()
        // must then strip back off.
        $result = PerfidiousResult::create(
            timeRunning: 1,
            timeEnabled: 1,
            revolutions: 1,
            rawValues: ['@foo' => 1],
        );

        $this->assertArrayHasKey('foo_raw', $result->values);
        $this->assertArrayNotHasKey('-foo_raw', $result->values);
    }
}
