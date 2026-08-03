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

namespace jbboehr\PhpBenchPerfidious\Tests\Executor;

use InvalidArgumentException;
use jbboehr\PhpBenchPerfidious\Executor\PerfidiousRemoteExecutor;
use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use jbboehr\PhpBenchPerfidious\Tests\Fixtures\ExecutorFixtureBenchmark;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Model\Result\MemoryResult;
use PhpBench\Model\Result\TimeResult;
use PhpBench\Model\ParameterSet;
use PhpBench\Registry\Config;
use PhpBench\Remote\Exception\ScriptErrorException;
use PhpBench\Remote\Launcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use UnexpectedValueException;

class PerfidiousRemoteExecutorTest extends TestCase
{
    private PerfidiousRemoteExecutor $executor;

    protected function setUp(): void
    {
        $launcher = new Launcher(bootstrap: __DIR__ . '/../bootstrap.php');

        // Software-only metric: reliable in sandboxed/virtualized CI environments
        // where hardware PMU counters are not available. It's also in TIME_EVENTS,
        // so a TimeResult is reliably produced.
        $this->executor = new PerfidiousRemoteExecutor($launcher, metrics: ['perf::PERF_COUNT_SW_CPU_CLOCK']);
    }

    /**
     * Resolves defaults (e.g. OPTION_SAFE_PARAMETERS) through the executor's own
     * configure(), the same way PHPBench itself builds a real Config -- a plain
     * `new Config('test', [])` skips those defaults entirely, which silently breaks
     * parameter serialization for any test that passes real parameters.
     *
     * @param array<string, mixed> $config
     */
    private function resolveConfig(array $config = []): Config
    {
        $resolver = new OptionsResolver();
        $this->executor->configure($resolver);

        $resolved = $resolver->resolve($config);
        $typed = [];
        foreach ($resolved as $key => $value) {
            assert(is_string($key));
            $typed[$key] = $value;
        }

        return new Config('test', $typed);
    }

    /**
     * @param list<string> $beforeMethods
     * @param list<string> $afterMethods
     * @param array<string, mixed> $parameters
     */
    private function makeContext(
        string $methodName,
        int $revolutions = 1,
        int $warmup = 0,
        array $beforeMethods = [],
        array $afterMethods = [],
        array $parameters = [],
    ): ExecutionContext {
        return new ExecutionContext(
            className: ExecutorFixtureBenchmark::class,
            classPath: (string) (new ReflectionClass(ExecutorFixtureBenchmark::class))->getFileName(),
            methodName: $methodName,
            revolutions: $revolutions,
            beforeMethods: $beforeMethods,
            afterMethods: $afterMethods,
            parameters: [] !== $parameters ? ParameterSet::fromUnserializedValues('test', $parameters) : null,
            warmup: $warmup,
        );
    }

    public function testExecuteReturnsPerfidiousResult(): void
    {
        $results = $this->executor->execute($this->makeContext('passes', 5), $this->resolveConfig());

        $result = $results->byType(PerfidiousResult::class)->first();

        $this->assertInstanceOf(PerfidiousResult::class, $result);
        $this->assertSame(5, $result->revolutions);
        $this->assertArrayHasKey('perf__PERF_COUNT_SW_CPU_CLOCK_raw', $result->values);
        $this->assertArrayHasKey('perf__PERF_COUNT_SW_CPU_CLOCK', $result->values);
    }

    public function testTimeAndMemoryResultsArePresent(): void
    {
        $results = $this->executor->execute($this->makeContext('passes', 5), $this->resolveConfig());

        $this->assertInstanceOf(TimeResult::class, $results->byType(TimeResult::class)->first());
        $this->assertInstanceOf(MemoryResult::class, $results->byType(MemoryResult::class)->first());
    }

    public function testExceptionFromBenchmarkMethodIsWrappedAsExecutionError(): void
    {
        try {
            $this->executor->execute($this->makeContext('throwsException'), $this->resolveConfig());
            $this->fail('Expected an ExecutionError to be thrown');
        } catch (ExecutionError $error) {
            $this->assertInstanceOf(ScriptErrorException::class, $error->getPrevious());
        }
    }

    public function testErrorFromBenchmarkMethodIsWrappedAsExecutionError(): void
    {
        $this->expectException(ExecutionError::class);

        $this->executor->execute($this->makeContext('throwsError'), $this->resolveConfig());
    }

    public function testMissingBenchmarkClassIsWrappedAsExecutionError(): void
    {
        $this->expectException(ExecutionError::class);

        $context = new ExecutionContext(
            className: 'jbboehr\PhpBenchPerfidious\Tests\Fixtures\DoesNotExist',
            classPath: __DIR__ . '/../Fixtures/DoesNotExist.php',
            methodName: 'passes',
        );

        $this->executor->execute($context, $this->resolveConfig());
    }

    public function testBenchmarkOutputIsReportedAsNoise(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Benchmark made some noise');

        $this->executor->execute($this->makeContext('echoesOutput'), $this->resolveConfig());
    }

    public function testBeforeAndAfterMethodsExecuteInChildProcess(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'perfidious-remote-test-');
        $this->assertIsString($marker);

        try {
            $this->executor->execute(
                $this->makeContext(
                    'passes',
                    beforeMethods: ['recordsBeforeMarker'],
                    afterMethods: ['recordsAfterMarker'],
                    parameters: ['marker' => $marker],
                ),
                $this->resolveConfig(),
            );

            // The only way these files can exist is if the child process actually ran
            // the before/after hooks -- there's no other channel back to this test.
            $this->assertFileExists($marker . '.before');
            $this->assertFileExists($marker . '.after');
        } finally {
            @unlink($marker . '.before');
            @unlink($marker . '.after');
            @unlink($marker);
        }
    }

    public function testWarmupAndMeasuredCallsSupportABenchmarkRequiringTheEmptyParameterArray(): void
    {
        $results = $this->executor->execute(
            $this->makeContext('recordsArgumentCount', revolutions: 3, warmup: 2),
            $this->resolveConfig(),
        );

        $this->assertInstanceOf(PerfidiousResult::class, $results->byType(PerfidiousResult::class)->first());
    }

    public function testConfigureRegistersBaseOptionsAndDefaultsSafeParametersToTrue(): void
    {
        $resolver = new OptionsResolver();
        $this->executor->configure($resolver);
        $resolved = $resolver->resolve([]);

        // These come from TemplateExecutor::configure(); if execute() ever stopped
        // calling parent::configure(), OPTION_PHP_CONFIG wouldn't be a registered
        // option at all and this would fail.
        $this->assertArrayHasKey(PerfidiousRemoteExecutor::OPTION_PHP_CONFIG, $resolved);
        $this->assertSame([], $resolved[PerfidiousRemoteExecutor::OPTION_PHP_CONFIG]);

        $this->assertTrue($resolved[PerfidiousRemoteExecutor::OPTION_SAFE_PARAMETERS]);
    }

    public function testConstructorRejectsMultipleRecognizedTimeEvents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At most one recognized time event is supported');

        new PerfidiousRemoteExecutor(new Launcher(), metrics: [
            'perf::PERF_COUNT_SW_CPU_CLOCK',
            'perf::TASK-CLOCK',
        ]);
    }

    public function testExecuteRejectsInvalidPhpConfigValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Executor option "php_config.memory_limit" must be scalar or an array of scalars, got stdClass',
        );

        $this->executor->execute(
            $this->makeContext('passes'),
            new Config('test', [
                PerfidiousRemoteExecutor::OPTION_PHP_CONFIG => ['memory_limit' => new \stdClass()],
            ]),
        );
    }

    public function testDecodeResultsReportsTheInvalidKeyAndType(): void
    {
        $method = new ReflectionMethod(PerfidiousRemoteExecutor::class, 'decodeResults');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Remote result key "mem.peak" must be an int, got string');

        $method->invoke($this->executor, $this->makeContext('passes'), [
            'mem' => ['peak' => 'invalid', 'real' => 1, 'final' => 1],
            'perf' => ['timeRunning' => 1, 'timeEnabled' => 1, 'rawValues' => []],
        ]);
    }

    public function testMissingExtensionHasATargetedDiagnosticInTheChildProcess(): void
    {
        $executor = new PerfidiousRemoteExecutor(
            new Launcher(bootstrap: __DIR__ . '/../bootstrap.php', phpDisableIni: true),
            metrics: ['perf::PERF_COUNT_SW_CPU_CLOCK'],
        );

        try {
            $executor->execute($this->makeContext('passes'), $this->resolveConfig());
            $this->fail('Expected an ExecutionError to be thrown');
        } catch (ExecutionError $error) {
            $this->assertStringContainsString('The perfidious PHP extension is required', $error->getMessage());
            $this->assertInstanceOf(ScriptErrorException::class, $error->getPrevious());
        }
    }

    public function testPhpConfigOptionForwardsScalarSettingToChildProcess(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'perfidious-remote-ini-');
        $this->assertIsString($marker);

        try {
            $config = $this->resolveConfig([
                PerfidiousRemoteExecutor::OPTION_PHP_CONFIG => ['memory_limit' => '123M'],
            ]);

            $this->executor->execute(
                $this->makeContext('recordsIniSetting', parameters: ['marker' => $marker, 'setting' => 'memory_limit']),
                $config,
            );

            $this->assertSame('123M', file_get_contents($marker));
        } finally {
            @unlink($marker);
        }
    }

    public function testPhpConfigOptionForwardsArrayValuedSettingToChildProcess(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'perfidious-remote-ini-');
        $this->assertIsString($marker);

        try {
            $config = $this->resolveConfig([
                PerfidiousRemoteExecutor::OPTION_PHP_CONFIG => ['memory_limit' => ['64M', '256M']],
            ]);

            $this->executor->execute(
                $this->makeContext('recordsIniSetting', parameters: ['marker' => $marker, 'setting' => 'memory_limit']),
                $config,
            );

            // Repeated `-d memory_limit=...` flags: the last one wins.
            $this->assertSame('256M', file_get_contents($marker));
        } finally {
            @unlink($marker);
        }
    }
}
