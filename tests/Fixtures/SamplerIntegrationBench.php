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

use PhpBench\Attributes\AfterClassMethods;
use PhpBench\Attributes\BeforeClassMethods;

if (!defined('PHPBENCH_SAMPLER_BOOTSTRAPPED')) {
    throw new \LogicException('Bootstrap must run before the benchmark class is loaded');
}

#[BeforeClassMethods('beforeClass')]
#[AfterClassMethods('afterClass')]
final class SamplerIntegrationBench
{
    private static bool $ready = false;

    public static function beforeClass(): void
    {
        if (!defined('PHPBENCH_SAMPLER_BOOTSTRAPPED')) {
            throw new \LogicException('Bootstrap must run before class hooks');
        }
        self::$ready = true;
        self::record('before-class');
    }

    public function benchSampler(): void
    {
        if (!self::$ready) {
            throw new \LogicException('Before-class hook must run in the benchmark process');
        }
        self::record('bench');
    }

    public static function afterClass(): void
    {
        if (!self::$ready) {
            throw new \LogicException('After-class hook must run in the benchmark process');
        }
        self::$ready = false;
        self::record('after-class');
    }

    private static function record(string $step): void
    {
        $marker = getenv('PHPBENCH_SAMPLER_MARKER');
        if (false === $marker) {
            throw new \LogicException('PHPBENCH_SAMPLER_MARKER is required');
        }
        file_put_contents($marker, $step . "\n", FILE_APPEND);
    }
}
