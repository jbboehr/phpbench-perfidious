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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class PhpBenchProfileTest extends TestCase
{
    public function testSamplerProfilePreservesLinuxRootDefaults(): void
    {
        $contents = file_get_contents(dirname(__DIR__) . '/phpbench.json');
        self::assertNotFalse($contents);
        $config = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($config);

        self::assertSame('perfidious-linux', $config['runner.executor']);
        self::assertSame('perfidious-linux', $config['runner.progress']);

        $profiles = $config['core.profiles'];
        self::assertIsArray($profiles);
        self::assertSame([
            'runner.executor' => 'perfidious',
            'runner.progress' => 'dots',
        ], $profiles['perfidious']);
    }

    /** @return iterable<string, array{bool}> */
    public static function samplerBenchmarkLoading(): iterable
    {
        yield 'autoloadable benchmark' => [true];
        yield 'benchmark discovered from a file' => [false];
    }

    #[DataProvider('samplerBenchmarkLoading')]
    public function testSamplerProfileRunsClassHooksAndProducesWallTimeWithTheDefaultReport(bool $autoloadable): void
    {
        $root = dirname(__DIR__);
        $marker = tempnam(sys_get_temp_dir(), 'sampler-class-hooks-');
        self::assertNotFalse($marker);
        $bootstrap = tempnam(sys_get_temp_dir(), 'sampler-hook-bootstrap-');
        self::assertNotFalse($bootstrap);
        file_put_contents($bootstrap, sprintf(
            '<?php require %s; define("PHPBENCH_SAMPLER_BOOTSTRAPPED", true);',
            var_export(__DIR__ . '/bootstrap.php', true),
        ));
        $benchmarkPath = $root . '/tests/Fixtures/SamplerIntegrationBench.php';
        $discoveredBenchmark = null;
        if (!$autoloadable) {
            $discoveredBenchmark = tempnam(sys_get_temp_dir(), 'sampler-discovered-');
            self::assertNotFalse($discoveredBenchmark);
            $contents = file_get_contents($benchmarkPath);
            self::assertNotFalse($contents);
            file_put_contents($discoveredBenchmark, str_replace(
                'namespace jbboehr\\PhpBenchPerfidious\\Tests\\Fixtures;',
                'namespace UnmappedSamplerFixtures;',
                $contents,
            ));
            $benchmarkPath = $discoveredBenchmark . '.php';
            rename($discoveredBenchmark, $benchmarkPath);
            $discoveredBenchmark = $benchmarkPath;
        }
        $process = new Process([
            PHP_BINARY,
            $root . '/vendor/bin/phpbench',
            'run',
            '--profile=perfidious',
            '--executor=perfidious',
            '--bootstrap=' . $bootstrap,
            '--iterations=1',
            '--revs=2',
            '--warmup=1',
            '--report=default',
            '--dump',
            $benchmarkPath,
        ], $root, ['PHPBENCH_SAMPLER_MARKER' => $marker]);
        $process->setTimeout(30);

        try {
            $process->run();
            $output = $process->getOutput();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput() . $output);
            self::assertStringContainsString('<executor name="perfidious">', $output);
            self::assertStringContainsString('perfidious_sampler-cpu-time-raw=', $output);
            self::assertStringContainsString('time-net=', $output);
            self::assertStringContainsString('SamplerIntegrationBench', $output);
            self::assertStringNotContainsString('PERF-COUNT-HW-INSTRUCTIONS', $output);
            self::assertSame("before-class\nbench\nbench\nbench\nafter-class\n", file_get_contents($marker));
        } finally {
            unlink($marker);
            unlink($bootstrap);
            if (null !== $discoveredBenchmark) {
                unlink($discoveredBenchmark);
            }
        }
    }

    public function testCiLinuxRemoteProfileUsesSoftwareCounter(): void
    {
        $root = dirname(__DIR__);
        $process = new Process([
            PHP_BINARY,
            $root . '/vendor/bin/phpbench',
            'run',
            '--profile=perfidious-linux-remote-ci',
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
        self::assertStringContainsString('<executor name="perfidious-linux-remote">', $process->getOutput());
        self::assertStringContainsString(
            '<result key="mem" class="PhpBench\\Model\\Result\\MemoryResult"/>',
            $process->getOutput(),
        );
        self::assertStringContainsString('perfidious-perf--PERF-COUNT-SW-CPU-CLOCK=', $process->getOutput());
        self::assertStringNotContainsString('PERF-COUNT-HW-INSTRUCTIONS', $process->getOutput());
    }
}
