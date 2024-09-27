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

use PhpBench\DependencyInjection\Container;
use PhpBench\DependencyInjection\ExtensionInterface;
use PhpBench\Executor\CompositeExecutor;
use PhpBench\Executor\Method\ErrorHandlingExecutorDecorator;
use PhpBench\Executor\Method\LocalMethodExecutor;
use PhpBench\Extension\RunnerExtension;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PerfidiousExtension implements ExtensionInterface
{
    public function configure(OptionsResolver $resolver): void
    {
    }

    public function load(Container $container): void
    {
        $container->register(PerfidiousExecutor::class . '.composite', function (Container $container) {
            $executor = $container->get(PerfidiousExecutor::class);
            assert($executor instanceof PerfidiousExecutor);

            $local_method_exectuor = $container->get(LocalMethodExecutor::class);
            assert($local_method_exectuor instanceof LocalMethodExecutor);

            return new CompositeExecutor(
                $executor,
                new ErrorHandlingExecutorDecorator($local_method_exectuor),
            );
        }, [RunnerExtension::TAG_EXECUTOR => ['name' => 'perfidious']]);

        $container->register(PerfidiousExecutor::class, function (Container $container) {
            $bootstrap = $container->getParameter(RunnerExtension::PARAM_BOOTSTRAP);
            assert(is_string($bootstrap) || is_null($bootstrap));

            return new PerfidiousExecutor(
                $bootstrap,
            );
        });
    }
}
