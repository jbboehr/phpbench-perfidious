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

use jbboehr\PhpBenchPerfidious\Executor\PerfidiousRemoteExecutor;
use jbboehr\PhpBenchPerfidious\Progress\PerfidiousProgressLogger;
use jbboehr\PhpBenchPerfidious\Progress\VariantSummaryFormatter;
use jbboehr\PhpBenchPerfidious\Report\PerfidiousGenerator;
use PhpBench\Assertion\ParameterProvider;
use PhpBench\DependencyInjection\Container;
use PhpBench\DependencyInjection\ExtensionInterface;
use PhpBench\Executor\CompositeExecutor;
use PhpBench\Executor\Method\ErrorHandlingExecutorDecorator;
use PhpBench\Executor\Method\LocalMethodExecutor;
use PhpBench\Executor\Method\RemoteMethodExecutor;
use PhpBench\Expression\ExpressionLanguage;
use PhpBench\Expression\Printer\EvaluatingPrinter;
use PhpBench\Extension\ConsoleExtension;
use PhpBench\Extension\ReportExtension;
use PhpBench\Extension\RunnerExtension;
use PhpBench\Remote\Launcher;
use PhpBench\Util\TimeUnit;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PerfidiousExtension implements ExtensionInterface
{
    final public const PARAM_PROGRESS_SUMMARY_BASELINE_FORMAT = 'perfidious.progress_summary_baseline_format';
    final public const PARAM_PROGRESS_SUMMARY_FORMAT = 'perfidious.progress_summary_variant_format';
    final public const PARAM_PERFIDIOUS_METRICS = 'perfidious.metrics';

    public function configure(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            self::PARAM_PERFIDIOUS_METRICS => PerfidiousExecutor::DEFAULT_METRICS,
            self::PARAM_PROGRESS_SUMMARY_FORMAT => VariantSummaryFormatter::DEFAULT_FORMAT,
            self::PARAM_PROGRESS_SUMMARY_BASELINE_FORMAT => VariantSummaryFormatter::BASELINE_FORMAT,
        ]);
        $resolver->setAllowedTypes(self::PARAM_PERFIDIOUS_METRICS, 'array');
        $resolver->setAllowedValues(
            self::PARAM_PERFIDIOUS_METRICS,
            static function (array $metrics): bool {
                foreach ($metrics as $metric) {
                    if (!is_string($metric)) {
                        return false;
                    }
                }

                return true;
            },
        );
        $resolver->setAllowedTypes(self::PARAM_PROGRESS_SUMMARY_FORMAT, 'string');
        $resolver->setAllowedTypes(self::PARAM_PROGRESS_SUMMARY_BASELINE_FORMAT, 'string');
    }

    public function load(Container $container): void
    {
        $container->register(PerfidiousExecutor::class . '.composite', static function (Container $container): CompositeExecutor {
            $executor = self::get($container, PerfidiousExecutor::class);
            $localMethodExecutor = self::get($container, LocalMethodExecutor::class);

            return new CompositeExecutor(
                $executor,
                new ErrorHandlingExecutorDecorator($localMethodExecutor),
            );
        }, [RunnerExtension::TAG_EXECUTOR => ['name' => 'perfidious']]);

        $container->register(PerfidiousExecutor::class, static function (Container $container): PerfidiousExecutor {
            $bootstrap = $container->getParameter(RunnerExtension::PARAM_BOOTSTRAP);
            if (!is_string($bootstrap) && null !== $bootstrap) {
                throw new \UnexpectedValueException(sprintf(
                    'Container parameter "%s" must be string or null, got %s',
                    RunnerExtension::PARAM_BOOTSTRAP,
                    get_debug_type($bootstrap),
                ));
            }

            $metrics = self::getStringListParameter($container, self::PARAM_PERFIDIOUS_METRICS);

            return PerfidiousExecutor::withMetrics(
                metrics: $metrics,
                bootstrap: $bootstrap,
            );
        });

        $container->register(PerfidiousRemoteExecutor::class . '.composite', static function (Container $container): CompositeExecutor {
            $executor = self::get($container, PerfidiousRemoteExecutor::class);
            $remoteMethodExecutor = self::get($container, RemoteMethodExecutor::class);

            return new CompositeExecutor(
                $executor,
                new ErrorHandlingExecutorDecorator($remoteMethodExecutor),
            );
        }, [RunnerExtension::TAG_EXECUTOR => ['name' => 'perfidious-remote']]);

        $container->register(PerfidiousRemoteExecutor::class, static function (Container $container): PerfidiousRemoteExecutor {
            $launcher = self::get($container, Launcher::class);
            $metrics = self::getStringListParameter($container, self::PARAM_PERFIDIOUS_METRICS);

            return new PerfidiousRemoteExecutor(
                launcher: $launcher,
                metrics: $metrics,
            );
        });

        $container->register(VariantSummaryFormatter::class, static function (Container $container): VariantSummaryFormatter {
            return new VariantSummaryFormatter(
                self::get($container, ExpressionLanguage::class),
                self::get($container, EvaluatingPrinter::class),
                self::get($container, ParameterProvider::class),
                self::getParameterString($container, self::PARAM_PROGRESS_SUMMARY_FORMAT),
                self::getParameterString($container, self::PARAM_PROGRESS_SUMMARY_BASELINE_FORMAT)
            );
        });

        $container->register(PerfidiousProgressLogger::class, static function (Container $container): PerfidiousProgressLogger {
            return new PerfidiousProgressLogger(
                self::get($container, OutputInterface::class, ConsoleExtension::SERVICE_OUTPUT_ERR),
                self::get($container, VariantSummaryFormatter::class),
                self::get($container, TimeUnit::class)
            );
        }, [
            RunnerExtension::TAG_PROGRESS_LOGGER => [
                'name' => 'perfidious',
            ]
        ]);

        $container->register(PerfidiousGenerator::class, function (Container $container) {
            return new PerfidiousGenerator();
        }, [
            ReportExtension::TAG_REPORT_GENERATOR => [
                'name' => 'perfidious',
            ]
        ]);
    }

    /**
     * @template T of object
     * @param Container $container
     * @param class-string<T> $class
     * @return T
     */
    private static function get(Container $container, string $class, ?string $key = null): object
    {
        $object = $container->get($key ?? $class);
        if (!is_object($object) || !is_a($object, $class)) {
            throw new \UnexpectedValueException(sprintf(
                'Container service "%s" must be an instance of %s, got %s',
                $key ?? $class,
                $class,
                get_debug_type($object),
            ));
        }

        return $object;
    }

    private static function getParameterString(Container $container, string $name): string
    {
        $param = $container->getParameter($name);
        if (!is_string($param)) {
            throw new \UnexpectedValueException(sprintf(
                'Container parameter "%s" must be a string, got %s',
                $name,
                get_debug_type($param),
            ));
        }

        return $param;
    }

    /**
     * @return list<string>
     */
    private static function getStringListParameter(Container $container, string $name): array
    {
        $param = $container->getParameter($name);
        if (!is_array($param)) {
            throw new \UnexpectedValueException(sprintf(
                'Container parameter "%s" must be an array, got %s',
                $name,
                get_debug_type($param),
            ));
        }

        $values = [];
        foreach ($param as $key => $value) {
            if (!is_string($value)) {
                throw new \UnexpectedValueException(sprintf(
                    'Container parameter "%s" value at key "%s" must be a string, got %s',
                    $name,
                    $key,
                    get_debug_type($value),
                ));
            }
            $values[] = $value;
        }

        return $values;
    }
}
