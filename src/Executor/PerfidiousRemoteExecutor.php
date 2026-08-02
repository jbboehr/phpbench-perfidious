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

use jbboehr\PhpBenchPerfidious\PerfidiousExecutor;
use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use PhpBench\Executor\Benchmark\TemplateExecutor;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\ExecutionResults;
use PhpBench\Model\Result\MemoryResult;
use PhpBench\Model\Result\TimeResult;
use PhpBench\Registry\Config;
use PhpBench\Remote\Exception\ScriptErrorException;
use PhpBench\Remote\Launcher;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PerfidiousRemoteExecutor extends TemplateExecutor
{
    private const DEFAULT_TEMPLATE_PATH = __DIR__ . '/template/perfidious-remote.template';

    /**
     * @param list<string> $metrics
     */
    public function __construct(
        // TemplateExecutor's own $launcher/$templatePath are private to that class (constructor
        // property promotion), so execute() below needs its own copies to work with.
        private readonly Launcher $launcher,
        private readonly array $metrics = PerfidiousExecutor::DEFAULT_METRICS,
        private readonly string $templatePath = self::DEFAULT_TEMPLATE_PATH,
    ) {
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

        return array_map(function (mixed $value): string {
            assert(is_scalar($value));
            return (string) $value;
        }, $tokens);
    }

    public function execute(ExecutionContext $context, Config $config): ExecutionResults
    {
        $tokens = $this->createTokens($context, $config);
        $payload = $this->launcher->payload($this->templatePath, $tokens, $context->getTimeOut());

        $phpConfigRaw = $config[self::OPTION_PHP_CONFIG] ?? [];
        assert(is_array($phpConfigRaw));

        $phpConfig = ['max_execution_time' => 0];
        foreach ($phpConfigRaw as $key => $value) {
            assert(is_string($key));

            if (is_array($value)) {
                $scalarList = [];
                foreach ($value as $item) {
                    assert(is_bool($item) || is_float($item) || is_int($item) || is_string($item));
                    $scalarList[] = $item;
                }
                $phpConfig[$key] = $scalarList;
                continue;
            }

            assert(is_bool($value) || is_float($value) || is_int($value) || is_string($value));
            $phpConfig[$key] = $value;
        }
        $payload->mergePhpConfig($phpConfig);

        try {
            $result = $payload->launch();
        } catch (ScriptErrorException $error) {
            throw new ExecutionError(sprintf(
                "Benchmarking script exited with code %s\n\n%s",
                $error->getExitCode() ?? 'unknown',
                $error->getMessage()
            ));
        }

        $buffer = $result['buffer'] ?? null;
        if (is_string($buffer) && '' !== $buffer) {
            throw new \RuntimeException(sprintf('Benchmark made some noise: %s', $buffer));
        }

        try {
            return $this->decodeResults($context, $result);
        } catch (ExecutionError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ExecutionError(sprintf(
                "Exception encountered decoding perfidious results: %s\n\n[%s]\n\n%s",
                $e->getMessage(),
                get_class($e),
                $e->getTraceAsString(),
            ));
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function decodeResults(ExecutionContext $context, array $result): ExecutionResults
    {
        $memRaw = $result['mem'];
        assert(is_array($memRaw));
        $mem = [];
        foreach ($memRaw as $key => $value) {
            assert(is_string($key));
            $mem[$key] = $value;
        }

        $perf = $result['perf'];
        assert(is_array($perf));

        $timeRunning = $perf['timeRunning'];
        $timeEnabled = $perf['timeEnabled'];
        $rawValuesRaw = $perf['rawValues'];
        assert(is_int($timeRunning));
        assert(is_int($timeEnabled));
        assert(is_array($rawValuesRaw));

        $rawValues = [];
        foreach ($rawValuesRaw as $eventName => $count) {
            assert(is_string($eventName));
            assert(is_int($count) || is_float($count));
            $rawValues[$eventName] = $count;
        }

        if ($timeRunning <= 0) {
            throw new \RuntimeException('perf_events failed to run');
        }

        $results = [
            MemoryResult::fromArray($mem),
            PerfidiousResult::create(
                timeRunning: $timeRunning,
                timeEnabled: $timeEnabled,
                revolutions: $context->getRevolutions(),
                rawValues: $rawValues,
            ),
        ];

        // Add a time result if available, matching PerfidiousExecutor's own methodology
        // rather than the wall-clock time PHPBench's stock remote executors use.
        foreach ($rawValues as $eventName => $count) {
            if (true === (PerfidiousExecutor::TIME_EVENTS[$eventName] ?? false)) {
                $adjusted = $count * $timeEnabled / $timeRunning / 1e3;
                $results[] = new TimeResult((int) $adjusted, $context->getRevolutions());
                break;
            }
        }

        return ExecutionResults::fromResults(...$results);
    }
}
