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

namespace jbboehr\PhpBenchPerfidious\Perf;

use Perfidious\Handle;
use Perfidious\ReadResult;

use function Perfidious\open;

/**
 * Thin adapter wrapping a real Perfidious\Handle, opened for the given
 * metrics list.
 */
final class NativeHandle implements HandleInterface
{
    /** @var Handle<list<string>> */
    private readonly Handle $handle;

    /**
     * @param list<string> $metrics
     */
    public function __construct(array $metrics)
    {
        $this->handle = open($metrics);
    }

    public function reset(): static
    {
        $this->handle->reset();

        return $this;
    }

    public function enable(): static
    {
        $this->handle->enable();

        return $this;
    }

    public function disable(): static
    {
        $this->handle->disable();

        return $this;
    }

    /**
     * @return ReadResult<list<string>>
     */
    public function read(): ReadResult
    {
        return $this->handle->read();
    }
}
