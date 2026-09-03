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

namespace jbboehr\PhpBenchPerfidious\Executor;

use InvalidArgumentException;
use jbboehr\PhpBenchPerfidious\LinuxExecutor;
use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use PhpBench\Executor\Benchmark\TemplateExecutor;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\ExecutionResults;
use PhpBench\Model\Result\MemoryResult;
use PhpBench\Registry\Config;
use PhpBench\Remote\Exception\ScriptErrorException;
use PhpBench\Remote\Launcher;
use Symfony\Component\OptionsResolver\OptionsResolver;
use UnexpectedValueException;

class LinuxRemoteExecutor extends TemplateExecutor
{
    private const DEFAULT_TEMPLATE_PATH = __DIR__ . '/template/linux-remote.template';

    /**
     * @param list<string> $metrics
     */
    public function __construct(
        // TemplateExecutor's own $launcher/$templatePath are private to that class (constructor
        // property promotion), so execute() below needs its own copies to work with.
        private readonly Launcher $launcher,
        private readonly array $metrics = LinuxExecutor::DEFAULT_METRICS,
        private readonly string $templatePath = self::DEFAULT_TEMPLATE_PATH,
    ) {
        LinuxExecutor::assertAtMostOneTimeEvent($metrics);
        parent::__construct($launcher, $templatePath);
    }

    public function configure(OptionsResolver $options): void
    {
        parent::configure($options);
        $options->setDefaults([
            self::OPTION_SAFE_PARAMETERS => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function createTokens(ExecutionContext $context, Config $config): array
    {
        $tokens = array_merge(parent::createTokens($context, $config), [
            'metrics' => var_export($this->metrics, true),
        ]);

        $stringTokens = [];
        foreach ($tokens as $key => $value) {
            if (!is_scalar($value)) {
                throw new UnexpectedValueException(sprintf(
                    'Remote template token "%s" must be scalar, got %s',
                    $key,
                    get_debug_type($value),
                ));
            }
            $stringTokens[$key] = (string) $value;
        }

        return $stringTokens;
    }

    public function execute(ExecutionContext $context, Config $config): ExecutionResults
    {
        $tokens = $this->createTokens($context, $config);
        $payload = $this->launcher->payload($this->templatePath, $tokens, $context->getTimeOut());

        $phpConfigRaw = $config[self::OPTION_PHP_CONFIG] ?? [];
        if (!is_array($phpConfigRaw)) {
            throw new InvalidArgumentException(sprintf(
                'Executor option "%s" must be an array, got %s',
                self::OPTION_PHP_CONFIG,
                get_debug_type($phpConfigRaw),
            ));
        }

        $phpConfig = ['max_execution_time' => 0];
        foreach ($phpConfigRaw as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Executor option "%s" keys must be strings, got %s key',
                    self::OPTION_PHP_CONFIG,
                    get_debug_type($key),
                ));
            }

            if (is_array($value)) {
                $scalarList = [];
                foreach ($value as $itemKey => $item) {
                    $scalarList[] = self::requirePhpConfigScalar(
                        $item,
                        self::OPTION_PHP_CONFIG . '.' . $key . '.' . $itemKey,
                    );
                }
                $phpConfig[$key] = $scalarList;
                continue;
            }

            $phpConfig[$key] = self::requirePhpConfigScalar(
                $value,
                self::OPTION_PHP_CONFIG . '.' . $key,
                'scalar or an array of scalars',
            );
        }
        $payload->mergePhpConfig($phpConfig);

        try {
            $result = $payload->launch();
        } catch (ScriptErrorException $error) {
            throw new ExecutionError(sprintf(
                "Benchmarking script exited with code %s\n\n%s",
                $error->getExitCode() ?? 'unknown',
                $error->getMessage()
            ), 0, $error);
        }

        try {
            $buffer = self::requireRemoteValue($result, 'buffer');
            if (!is_string($buffer)) {
                throw new UnexpectedValueException(sprintf(
                    'Remote result key "buffer" must be a string, got %s',
                    get_debug_type($buffer),
                ));
            }
            if ('' !== $buffer) {
                throw new \RuntimeException(sprintf('Benchmark made some noise: %s', $buffer));
            }

            return $this->decodeResults($context, $result);
        } catch (ExecutionError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ExecutionError(sprintf(
                "Exception encountered decoding perfidious results: %s\n\n[%s]\n\n%s",
                $e->getMessage(),
                get_class($e),
                $e->getTraceAsString(),
            ), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function decodeResults(ExecutionContext $context, array $result): ExecutionResults
    {
        $memRaw = self::requireRemoteArray($result, 'mem');
        $mem = [
            'peak' => self::requireRemoteInt($memRaw, 'peak', 'mem'),
            'real' => self::requireRemoteInt($memRaw, 'real', 'mem'),
            'final' => self::requireRemoteInt($memRaw, 'final', 'mem'),
        ];

        $perf = self::requireRemoteArray($result, 'perf');
        $timeRunning = self::requireRemoteInt($perf, 'timeRunning', 'perf');
        $timeEnabled = self::requireRemoteInt($perf, 'timeEnabled', 'perf');
        $rawValuesRaw = self::requireRemoteArray($perf, 'rawValues', 'perf');

        $rawValues = [];
        foreach ($rawValuesRaw as $eventName => $count) {
            if (!is_string($eventName)) {
                throw new UnexpectedValueException(sprintf(
                    'Remote result key "perf.rawValues" must contain string event names, got %s key',
                    get_debug_type($eventName),
                ));
            }
            if (!is_int($count) && !is_float($count)) {
                throw new UnexpectedValueException(sprintf(
                    'Remote result key "perf.rawValues.%s" must be int or float, got %s',
                    $eventName,
                    get_debug_type($count),
                ));
            }
            $rawValues[$eventName] = $count;
        }

        LinuxExecutor::assertCountersRan($timeRunning);

        $results = [
            MemoryResult::fromArray($mem),
            PerfidiousResult::create(
                timeRunning: $timeRunning,
                timeEnabled: $timeEnabled,
                revolutions: $context->getRevolutions(),
                rawValues: $rawValues,
            ),
        ];

        // Add a time result if available, matching LinuxExecutor's own methodology
        // rather than the wall-clock time PHPBench's stock remote executors use.
        $timeResult = LinuxExecutor::createTimeResult(
            $rawValues,
            $timeEnabled,
            $timeRunning,
            $context->getRevolutions(),
        );
        if (null !== $timeResult) {
            $results[] = $timeResult;
        }

        return ExecutionResults::fromResults(...$results);
    }

    private static function requirePhpConfigScalar(
        mixed $value,
        string $path,
        string $expected = 'scalar',
    ): bool|float|int|string {
        if (is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf(
            'Executor option "%s" must be %s, got %s',
            $path,
            $expected,
            get_debug_type($value),
        ));
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function requireRemoteValue(array $values, string $key, string $parent = ''): mixed
    {
        $path = '' === $parent ? $key : $parent . '.' . $key;
        if (!array_key_exists($key, $values)) {
            throw new UnexpectedValueException(sprintf('Remote result is missing required key "%s"', $path));
        }

        return $values[$key];
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function requireRemoteArray(array $values, string $key, string $parent = ''): array
    {
        $value = self::requireRemoteValue($values, $key, $parent);
        if (!is_array($value)) {
            $path = '' === $parent ? $key : $parent . '.' . $key;
            throw new UnexpectedValueException(sprintf(
                'Remote result key "%s" must be an array, got %s',
                $path,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function requireRemoteInt(array $values, string $key, string $parent = ''): int
    {
        $value = self::requireRemoteValue($values, $key, $parent);
        if (!is_int($value)) {
            $path = '' === $parent ? $key : $parent . '.' . $key;
            throw new UnexpectedValueException(sprintf(
                'Remote result key "%s" must be an int, got %s',
                $path,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
