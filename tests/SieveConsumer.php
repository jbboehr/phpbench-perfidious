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
