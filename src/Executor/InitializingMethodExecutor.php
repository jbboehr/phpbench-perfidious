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

use PhpBench\Executor\MethodExecutorContext;
use PhpBench\Executor\MethodExecutorInterface;

final class InitializingMethodExecutor implements MethodExecutorInterface
{
    public function __construct(
        private readonly MethodExecutorInterface $executor,
        private readonly ?string $bootstrap = null,
    ) {
    }

    /** @param array<array-key, mixed> $methods */
    public function executeMethods(MethodExecutorContext $context, array $methods): void
    {
        if (null !== $this->bootstrap) {
            require_once($this->bootstrap);
        }
        if (!class_exists($context->getBenchmarkClass())) {
            require_once($context->getBenchmarkPath());
        }

        $this->executor->executeMethods($context, $methods);
    }
}
