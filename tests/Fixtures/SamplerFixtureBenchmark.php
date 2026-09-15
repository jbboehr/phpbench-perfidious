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

final class SamplerFixtureBenchmark
{
    public static ?FakeSampler $sampler = null;

    /** @var list<array{string, array<string, mixed>, bool}> */
    public static array $calls = [];

    public function __construct()
    {
        $this->record('construct', []);
    }

    /** @param array<string, mixed> $parameters */
    public function before(array $parameters): void
    {
        $this->record('before', $parameters);
    }

    /** @param array<string, mixed> $parameters */
    public function bench(array $parameters): void
    {
        $this->record('bench', $parameters);
    }

    /** @param array<string, mixed> $parameters */
    public function after(array $parameters): void
    {
        $this->record('after', $parameters);
    }

    /** @param array{count: int} $parameters */
    public function mutatesParameters(array &$parameters): void
    {
        ++$parameters['count'];
        $this->record('mutate', $parameters);
    }

    /** @param array<string, mixed> $parameters */
    private function record(string $step, array $parameters): void
    {
        self::$calls[] = [$step, $parameters, self::$sampler->measuring ?? false];
    }
}

final class SamplerFixtureParameter
{
    /** @return array{} */
    public function __serialize(): array
    {
        return [];
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        SamplerFixtureBenchmark::$calls[] = [
            'unserialize',
            [],
            SamplerFixtureBenchmark::$sampler->measuring ?? false,
        ];
    }
}
