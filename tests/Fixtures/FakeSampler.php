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

namespace jbboehr\PhpBenchPerfidious\Tests\Fixtures;

use jbboehr\PhpBenchPerfidious\Sampler\Measurement;
use jbboehr\PhpBenchPerfidious\Sampler\SamplerInterface;

final class FakeSampler implements SamplerInterface
{
    public int $measurements = 0;
    public bool $measuring = false;

    /** @param array<string, int> $values */
    public function __construct(
        private readonly int $elapsedTimeNs = 9_000_000,
        private readonly array $values = ['cpu-time' => 12_000],
    ) {
    }

    public function measure(callable $operation): Measurement
    {
        ++$this->measurements;
        $this->measuring = true;
        try {
            $operation();

            return new Measurement($this->elapsedTimeNs, $this->values);
        } finally {
            $this->measuring = false;
        }
    }
}
