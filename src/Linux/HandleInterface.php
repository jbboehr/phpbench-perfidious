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

namespace jbboehr\PhpBenchPerfidious\Linux;

use Perfidious\ReadResult;

/**
 * Mirrors the subset of Perfidious\Handle's API that LinuxExecutor
 * uses. Handle is a `final` class from a native extension, so it can't be
 * mocked/subclassed directly -- this seam exists purely so tests can supply
 * a fake implementation instead.
 */
interface HandleInterface
{
    public function reset(): static;

    public function enable(): static;

    public function disable(): static;

    /**
     * @return ReadResult<list<string>>
     */
    public function read(): ReadResult;
}
