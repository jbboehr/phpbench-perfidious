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

class ExecutorFixtureBenchmark
{
    public static int $callCount = 0;

    public function passes(): void
    {
        $sum = 0;
        for ($i = 0; $i < 1000; $i++) {
            $sum += $i;
        }
    }

    public function increments(): void
    {
        self::$callCount++;
    }

    public function throwsException(): void
    {
        throw new \RuntimeException('deliberate exception from benchmark');
    }

    public function throwsError(): void
    {
        throw new \Error('deliberate error from benchmark');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function recordsBeforeMarker(array $parameters): void
    {
        $marker = $parameters['marker'];
        assert(is_string($marker));
        file_put_contents($marker . '.before', '1');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function recordsAfterMarker(array $parameters): void
    {
        $marker = $parameters['marker'];
        assert(is_string($marker));
        file_put_contents($marker . '.after', '1');
    }

    public function echoesOutput(): void
    {
        echo 'unexpected output';
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function recordsIniSetting(array $parameters): void
    {
        $marker = $parameters['marker'];
        $setting = $parameters['setting'];
        assert(is_string($marker) && is_string($setting));
        file_put_contents($marker, (string) ini_get($setting));
    }
}
