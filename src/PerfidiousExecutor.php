<?php
/**
 * Copyright (c) anno Domini nostri Jesu Christi MMXXIV John Boehr & contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace jbboehr\PhpBenchPerfidious;

use Perfidious\Handle;
use PhpBench\Executor\BenchmarkExecutorInterface;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\ExecutionResults;
use PhpBench\Model\Result\TimeResult;
use PhpBench\Registry\Config;
use Symfony\Component\OptionsResolver\OptionsResolver;
use function Perfidious\open;

class PerfidiousExecutor implements BenchmarkExecutorInterface
{
    public const DEFAULT_METRICS = [
        'perf::PERF_COUNT_SW_CPU_CLOCK',
        'perf::PERF_COUNT_HW_INSTRUCTIONS',
    ];

    private const TIME_EVENTS = [
        'perf::PERF_COUNT_SW_CPU_CLOCK' => true,
        'perf::CPU-CLOCK' => true,
        'perf::PERF_COUNT_SW_TASK_CLOCK' => true,
        'perf::TASK-CLOCK' => true,
    ];

    /** @var list<string>  */
    private array $metrics;

    /** @var Handle<list<string>> */
    private Handle $handle;

    /**
     * @param ?list<string> $metrics
     */
    public function __construct(
        private readonly ?string $bootstrap = null,
        ?array $metrics = null,
    ) {
        $this->metrics = $metrics ?? self::DEFAULT_METRICS;
        $this->handle = open($this->metrics);
    }

    public function configure(OptionsResolver $options): void
    {
    }

    public function execute(ExecutionContext $context, Config $config): ExecutionResults
    {
        try {
            return $this->doExecute($context, $config);
        } catch (\Exception $e) {
            throw new ExecutionError(
                sprintf("Exception encountered in benchmark: %s\n\n[%s]\n\n%s", $e->getMessage(), get_class($e), $e->getTraceAsString()),
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

        foreach ($context->getBeforeMethods() as $afterMethod) {
            assert(method_exists($benchmark, $afterMethod));
            /** @phpstan-ignore-next-line method.dynamicName */
            $benchmark->{$afterMethod}($parameters);
        }

        for ($i = 0; $i < $context->getWarmup() ?: 0; $i++) {
            /** @phpstan-ignore-next-line method.dynamicName */
            $benchmark->{$methodName}($parameters);
        }

        $this->handle->reset();
        $this->handle->enable();

        for ($i = 0; $i < $context->getRevolutions(); $i++) {
            /** @phpstan-ignore-next-line method.dynamicName */
            $benchmark->{$methodName}($parameters);
        }

        $this->handle->disable();
        $rr = $this->handle->read();

        if ($rr->timeRunning <= 0) {
            throw new \RuntimeException('perf_events failed to run');
        }

        $results = [];

        $results[] = PerfidiousResult::create(
            timeRunning: $rr->timeRunning,
            timeEnabled: $rr->timeEnabled,
            revolutions: $context->getRevolutions(),
            rawValues: $rr->values,
        );

        // Add a time result if available
        foreach ($rr->values as $eventName => $count) {
            if (true === (self::TIME_EVENTS[$eventName] ?? false)) {
                $adjusted = $count * $rr->timeEnabled / $rr->timeRunning / 1e3;
                $results[] = new TimeResult((int) $adjusted, $context->getRevolutions());
            }
        }

        foreach ($context->getAfterMethods() as $afterMethod) {
            assert(method_exists($benchmark, $afterMethod));
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
