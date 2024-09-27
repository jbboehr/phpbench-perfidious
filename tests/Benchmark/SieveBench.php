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

namespace jbboehr\PhpBenchPerfidious\Tests\Benchmark;

use jbboehr\PhpBenchPerfidious\Tests\SieveConsumer;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;

final class SieveBench
{
    #[Revs(5)]
    #[Iterations(5)]
    public function benchArray(): void
    {
        $v = (new SieveConsumer())->calc(100_000);
        assert(count($v) > 0);
    }

    #[Revs(5)]
    #[Iterations(5)]
    public function benchString(): void
    {
        $v = (new SieveConsumer())->calcString(100_000);
        assert(count($v) > 0);
    }
}
