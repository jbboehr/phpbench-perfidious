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

use jbboehr\PhpBenchPerfidious\Report\PerfidiousGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PerfidiousGeneratorTest extends TestCase
{
    public function testConfigureSetsMeaningfulDefaultTitleAndDescription(): void
    {
        $generator = new PerfidiousGenerator();
        $resolver = new OptionsResolver();
        $generator->configure($resolver);

        $resolved = $resolver->resolve();

        $this->assertSame('Perfidious report', $resolved['title']);
        $this->assertIsString($resolved['description']);
        $this->assertNotSame('', $resolved['description']);
    }
}
