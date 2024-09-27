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

namespace jbboehr\PhpBenchPerfidious\Tests;

class SieveConsumer
{
    /**
     * @return list<int>
     */
    public function calc(int $n)
    {
        $lut = array_fill(0, $n, null);
        // $lut = new SplFixedArray($n);
        for ($i = 2; $i < $n; $i++) {
            for ($j = $i + $i; $j < $n; $j += $i) {
                $lut[$j] = true;
            }
        }
        $rv = [];
        for ($i = 2; $i < $n; $i++) {
            if (null === $lut[$i]) {
                $rv[] = $i;
            }
        }
        return $rv;
    }

    /**
     * @return list<int>
     */
    public function calcString(int $n): array
    {
        $lut = str_repeat('0', $n);
        for ($i = 2; $i < $n; $i++) {
            for ($j = $i + $i; $j < $n; $j += $i) {
                $lut[$j] = '1';
            }
        }
        $rv = [];
        for ($i = 2; $i < $n; $i++) {
            if ('0' === $lut[$i]) {
                $rv[] = $i;
            }
        }
        return $rv;
    }
}
