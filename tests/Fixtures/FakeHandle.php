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

namespace jbboehr\PhpBenchPerfidious\Tests\Fixtures;

use jbboehr\PhpBenchPerfidious\Perf\HandleInterface;
use Perfidious\ReadResult;
use ReflectionClass;

/**
 * Test double for Perf\HandleInterface. Perfidious\ReadResult has no public
 * constructor (it's populated internally by the native extension), so
 * fakeReadResult() builds one via reflection -- readonly properties can be
 * set exactly once that way even from outside the declaring class, which is
 * enough to construct a fully controlled result for tests.
 */
final class FakeHandle implements HandleInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var ReadResult<list<string>> */
    private readonly ReadResult $readResult;

    /**
     * @param array<string, int|float> $values
     */
    public function __construct(int $timeRunning = 1_000_000, int $timeEnabled = 1_000_000, array $values = [])
    {
        $this->readResult = self::fakeReadResult($timeRunning, $timeEnabled, $values);
    }

    /**
     * @param array<string, int|float> $values
     * @return ReadResult<list<string>>
     */
    public static function fakeReadResult(int $timeRunning, int $timeEnabled, array $values): ReadResult
    {
        $result = new ReadResult();
        $reflection = new ReflectionClass($result);
        $reflection->getProperty('timeRunning')->setValue($result, $timeRunning);
        $reflection->getProperty('timeEnabled')->setValue($result, $timeEnabled);
        $reflection->getProperty('values')->setValue($result, $values);

        return $result;
    }

    public function reset(): static
    {
        $this->calls[] = 'reset';

        return $this;
    }

    public function enable(): static
    {
        $this->calls[] = 'enable';

        return $this;
    }

    public function disable(): static
    {
        $this->calls[] = 'disable';

        return $this;
    }

    public function read(): ReadResult
    {
        $this->calls[] = 'read';

        return $this->readResult;
    }
}
