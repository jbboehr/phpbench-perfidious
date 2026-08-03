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

namespace jbboehr\PhpBenchPerfidious\Tests\Perf;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class NativeHandleTest extends TestCase
{
    public function testMissingExtensionHasATargetedDiagnostic(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = sprintf(
            'require %s; new jbboehr\\PhpBenchPerfidious\\Perf\\NativeHandle([]);',
            var_export($autoload, true),
        );
        $process = new Process([PHP_BINARY, '-n', '-d', 'display_errors=stderr', '-r', $script]);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'The perfidious PHP extension is required; install and enable ext-perfidious',
            $process->getErrorOutput() . $process->getOutput(),
        );
    }
}
