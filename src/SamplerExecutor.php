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

use jbboehr\PhpBenchPerfidious\Sampler\NativeSampler;
use jbboehr\PhpBenchPerfidious\Sampler\SamplerInterface;
use PhpBench\Executor\BenchmarkExecutorInterface;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\ExecutionResults;
use PhpBench\Model\Result\TimeResult;
use PhpBench\Registry\Config;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SamplerExecutor implements BenchmarkExecutorInterface
{
    public const METRICS = ['cpu-time', 'page-faults', 'context-switches', 'cpu-cycles', 'instructions'];

    public const DEFAULT_METRICS = ['cpu-time'];

    public function __construct(
        private readonly SamplerInterface $sampler,
        private readonly ?string $bootstrap = null,
    ) {
    }

    /** @param ?list<string> $metrics */
    public static function withMetrics(?array $metrics = null, ?string $bootstrap = null): self
    {
        return new self(new NativeSampler($metrics ?? self::DEFAULT_METRICS), $bootstrap);
    }

    public function configure(OptionsResolver $options): void
    {
    }

    public function execute(ExecutionContext $context, Config $config): ExecutionResults
    {
        try {
            return $this->doExecute($context);
        } catch (\Throwable $error) {
            throw new ExecutionError(
                sprintf(
                    "Exception encountered in benchmark: %s\n\n[%s]\n\n%s",
                    $error->getMessage(),
                    get_class($error),
                    $error->getTraceAsString(),
                ),
                0,
                $error,
            );
        }
    }

    private function doExecute(ExecutionContext $context): ExecutionResults
    {
        if (null !== $this->bootstrap) {
            require_once($this->bootstrap);
        }

        $benchmark = $this->createBenchmark($context);
        $method = $this->benchmarkMethod($benchmark, $context->getMethodName());
        $parameters = $context->getParameterSet()->toUnserializedParameters();
        $revolutions = $context->getRevolutions();

        foreach ($context->getBeforeMethods() as $beforeMethod) {
            ($this->benchmarkMethod($benchmark, $beforeMethod))($parameters);
        }

        for ($i = 0; $i < $context->getWarmup(); ++$i) {
            $method($parameters);
        }

        $measurement = $this->sampler->measure(static function () use ($method, &$parameters, $revolutions): void {
            for ($i = 0; $i < $revolutions; ++$i) {
                $method($parameters);
            }
        });

        foreach ($context->getAfterMethods() as $afterMethod) {
            ($this->benchmarkMethod($benchmark, $afterMethod))($parameters);
        }

        return ExecutionResults::fromResults(
            SamplerResult::create($measurement->elapsedTimeNs, $revolutions, $measurement->values),
            new TimeResult(intdiv($measurement->elapsedTimeNs, 1_000), $revolutions),
        );
    }

    /** @return callable(array<array-key, mixed>): mixed */
    private function benchmarkMethod(object $benchmark, string $methodName): callable
    {
        $method = [$benchmark, $methodName];
        if (!is_callable($method)) {
            throw new \BadMethodCallException(sprintf(
                'Method is not callable: %s on %s',
                $methodName,
                get_class($benchmark),
            ));
        }

        return $method;
    }

    private function createBenchmark(ExecutionContext $context): object
    {
        $className = $context->getClassName();
        if (!class_exists($className)) {
            require_once($context->getClassPath());
        }
        if (!class_exists($className)) {
            throw new ExecutionError(sprintf('Benchmark class "%s" does not exist', $className));
        }

        return new $className();
    }
}
