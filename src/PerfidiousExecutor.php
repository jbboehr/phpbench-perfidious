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

namespace jbboehr\PhpBenchPerfidious;

use InvalidArgumentException;
use jbboehr\PhpBenchPerfidious\Linux\HandleInterface;
use jbboehr\PhpBenchPerfidious\Linux\NativeHandle;
use PhpBench\Executor\BenchmarkExecutorInterface;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\ExecutionResults;
use PhpBench\Model\Result\TimeResult;
use PhpBench\Registry\Config;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PerfidiousExecutor implements BenchmarkExecutorInterface
{
    public const DEFAULT_METRICS = [
        'perf::PERF_COUNT_SW_CPU_CLOCK',
        'perf::PERF_COUNT_HW_INSTRUCTIONS',
    ];

    public const TIME_EVENTS = [
        'perf::PERF_COUNT_SW_CPU_CLOCK' => true,
        'perf::CPU-CLOCK' => true,
        'perf::PERF_COUNT_SW_TASK_CLOCK' => true,
        'perf::TASK-CLOCK' => true,
    ];

    public function __construct(
        private readonly HandleInterface $handle,
        private readonly ?string $bootstrap = null,
    ) {
    }

    /**
     * Convenience constructor for the common case: opens a real perf handle
     * for the given metrics (or DEFAULT_METRICS). Most callers want this;
     * the primary constructor exists so tests can inject a fake HandleInterface.
     *
     * @param ?list<string> $metrics
     */
    public static function withMetrics(?array $metrics = null, ?string $bootstrap = null): self
    {
        $metrics ??= self::DEFAULT_METRICS;
        self::assertAtMostOneTimeEvent($metrics);

        return new self(
            handle: new NativeHandle($metrics),
            bootstrap: $bootstrap,
        );
    }

    public function configure(OptionsResolver $options): void
    {
    }

    /**
     * Converts a raw perf counter value that represents elapsed time into
     * microseconds, adjusted for multiplexing (timeEnabled/timeRunning) the
     * kernel may have applied when more counters were requested than the
     * CPU has hardware slots for. Pulled out into a pure function -- shared
     * with PerfidiousRemoteExecutor -- so it can be unit tested with fixed
     * inputs instead of relying on real (non-deterministic) perf timings.
     */
    public static function adjustedTime(int|float $count, int $timeEnabled, int $timeRunning): int
    {
        return (int) ($count * $timeEnabled / $timeRunning / 1e3);
    }

    /**
     * @param list<string> $eventNames
     */
    public static function assertAtMostOneTimeEvent(array $eventNames): void
    {
        $timeEvents = array_values(array_filter(
            $eventNames,
            static fn (string $eventName): bool => true === (self::TIME_EVENTS[$eventName] ?? false),
        ));

        if (count($timeEvents) <= 1) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'At most one recognized time event is supported; got "%s"',
            implode('", "', $timeEvents),
        ));
    }

    /**
     * @param array<string, int|float> $rawValues
     */
    public static function createTimeResult(
        array $rawValues,
        int $timeEnabled,
        int $timeRunning,
        int $revolutions,
    ): ?TimeResult {
        $eventNames = array_keys($rawValues);
        self::assertAtMostOneTimeEvent($eventNames);

        foreach ($rawValues as $eventName => $count) {
            if (true !== (self::TIME_EVENTS[$eventName] ?? false)) {
                continue;
            }

            return new TimeResult(
                self::adjustedTime($count, $timeEnabled, $timeRunning),
                $revolutions,
            );
        }

        return null;
    }

    /**
     * @throws \RuntimeException if perf_events never actually ran (a handle
     *         was read but its timeRunning never advanced)
     */
    public static function assertCountersRan(int $timeRunning): void
    {
        if ($timeRunning <= 0) {
            throw new \RuntimeException('perf_events failed to run');
        }
    }

    public function execute(ExecutionContext $context, Config $config): ExecutionResults
    {
        try {
            return $this->doExecute($context, $config);
        } catch (\Throwable $e) {
            throw new ExecutionError(
                sprintf("Exception encountered in benchmark: %s\n\n[%s]\n\n%s", $e->getMessage(), get_class($e), $e->getTraceAsString()),
                0,
                $e,
            );
        }
    }

    private function doExecute(ExecutionContext $context, Config $config): ExecutionResults
    {
        if (null !== $this->bootstrap) {
            /** @psalm-suppress UnresolvableInclude */
            require_once($this->bootstrap);
        }

        $benchmark = $this->createBenchmark($context);

        $methodName = $context->getMethodName();
        $parameters = $context->getParameterSet()->toUnserializedParameters();

        if (!method_exists($benchmark, $methodName)) {
            throw new \BadMethodCallException('Method does not exist: ' . $methodName . ' on ' . get_class($benchmark));
        }

        foreach ($context->getBeforeMethods() as $beforeMethod) {
            if (!method_exists($benchmark, $beforeMethod)) {
                throw new \BadMethodCallException(
                    'Before method does not exist: ' . $beforeMethod . ' on ' . get_class($benchmark),
                );
            }
            /** @phpstan-ignore-next-line method.dynamicName */
            $benchmark->{$beforeMethod}($parameters);
        }

        for ($i = 0; $i < $context->getWarmup() ?: 0; $i++) {
            /** @phpstan-ignore-next-line method.dynamicName */
            $benchmark->{$methodName}($parameters);
        }

        $this->handle->reset();
        $this->handle->enable();

        try {
            for ($i = 0; $i < $context->getRevolutions(); $i++) {
                /** @phpstan-ignore-next-line method.dynamicName */
                $benchmark->{$methodName}($parameters);
            }
        } finally {
            $this->handle->disable();
        }

        $rr = $this->handle->read();

        self::assertCountersRan($rr->timeRunning);

        $results = [];

        $results[] = PerfidiousResult::create(
            timeRunning: $rr->timeRunning,
            timeEnabled: $rr->timeEnabled,
            revolutions: $context->getRevolutions(),
            rawValues: $rr->values,
        );

        $timeResult = self::createTimeResult(
            $rr->values,
            $rr->timeEnabled,
            $rr->timeRunning,
            $context->getRevolutions(),
        );
        if (null !== $timeResult) {
            $results[] = $timeResult;
        }

        foreach ($context->getAfterMethods() as $afterMethod) {
            if (!method_exists($benchmark, $afterMethod)) {
                throw new \BadMethodCallException(
                    'After method does not exist: ' . $afterMethod . ' on ' . get_class($benchmark),
                );
            }
            /** @phpstan-ignore-next-line method.dynamicName */
            $benchmark->{$afterMethod}($parameters);
        }

        return ExecutionResults::fromResults(...$results);
    }

    /**
     * @return object
     */
    private function createBenchmark(ExecutionContext $context)
    {
        $className = $context->getClassName();

        if (!class_exists($className)) {
            require_once($context->getClassPath());
        }

        if (!class_exists($className)) {
            throw new ExecutionError(sprintf(
                'Benchmark class "%s" does not exist',
                $className
            ));
        }

        return new $className();
    }
}
