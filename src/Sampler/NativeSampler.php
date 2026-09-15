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

namespace jbboehr\PhpBenchPerfidious\Sampler;

use Perfidious\Metric;
use Perfidious\Sampler;
use Perfidious\Scope;

final class NativeSampler implements SamplerInterface
{
    /** @var non-empty-list<Metric> */
    private readonly array $metrics;

    private readonly Scope $scope;

    /**
     * @param list<string> $metrics
     * @throws \ValueError if the metric list is empty or contains unknown or duplicate names
     * @throws \RuntimeException if the perfidious sampler API is unavailable
     */
    public function __construct(array $metrics, ?Scope $scope = null)
    {
        if (!class_exists(Sampler::class)) {
            throw new \RuntimeException(
                'The perfidious sampler API is required; install and enable ext-perfidious 0.3.1 or newer',
            );
        }
        if ($metrics === []) {
            throw new \ValueError('At least one sampler metric is required');
        }

        $this->metrics = array_map(Metric::from(...), $metrics);
        if (count(array_unique($metrics)) !== count($metrics)) {
            throw new \ValueError('Sampler metrics must not contain duplicate names');
        }
        $this->scope = $scope ?? Scope::CurrentThread;
    }

    public function measure(callable $operation): Measurement
    {
        $sampler = Sampler::open($this->metrics, $this->scope);

        try {
            $before = $sampler->read();
            $operation();
            $delta = $sampler->read()->since($before);

            $values = [];
            foreach ($this->metrics as $metric) {
                $values[$metric->value] = $delta->value($metric);
            }

            return new Measurement($delta->elapsedTimeNs, $values);
        } finally {
            $sampler->close();
        }
    }
}
