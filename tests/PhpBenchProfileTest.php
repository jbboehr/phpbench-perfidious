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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PhpBenchProfileTest extends TestCase
{
    public function testCiRemoteProfileUsesPortableSoftwareCounter(): void
    {
        $root = dirname(__DIR__);
        $process = new Process([
            PHP_BINARY,
            $root . '/vendor/bin/phpbench',
            'run',
            '--profile=perfidious-remote-ci',
            '--iterations=1',
            '--revs=1',
            '--warmup=1',
            '--progress=none',
            '--dump',
            $root . '/tests/Benchmark/SieveBench.php',
        ], $root);
        $process->setTimeout(30);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());
        self::assertStringContainsString('<executor name="perfidious-remote">', $process->getOutput());
        self::assertStringContainsString(
            '<result key="mem" class="PhpBench\\Model\\Result\\MemoryResult"/>',
            $process->getOutput(),
        );
        self::assertStringContainsString('perfidious-perf--PERF-COUNT-SW-CPU-CLOCK=', $process->getOutput());
        self::assertStringNotContainsString('PERF-COUNT-HW-INSTRUCTIONS', $process->getOutput());
    }
}
