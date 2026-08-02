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

namespace jbboehr\PhpBenchPerfidious\Report;

use jbboehr\PhpBenchPerfidious\PerfidiousResult;
use PhpBench\Dom\Document;
use PhpBench\Expression\Ast\IntegerNode;
use PhpBench\Expression\Ast\StringNode;
use PhpBench\Model\SuiteCollection;
use PhpBench\Registry\Config;
use PhpBench\Report\GeneratorInterface;
use PhpBench\Report\Model\Builder\ReportBuilder;
use PhpBench\Report\Model\Builder\TableBuilder;
use PhpBench\Report\Model\Report;
use PhpBench\Report\Model\Reports;
use PhpBench\Report\Model\Table;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PerfidiousGenerator implements GeneratorInterface
{
    public function configure(OptionsResolver $options): void
    {
        $options->setDefaults([
            'title' => 'Perfidious report',
            'description' => 'Per-iteration hardware/software performance counter results.',
        ]);
    }

    public function generate(SuiteCollection $suiteCollection, Config $config): Reports
    {
        $builder = ReportBuilder::create();

        foreach ($suiteCollection as $suite) {
            $rows = [];

            foreach ($suite->getBenchmarks() as $benchmark) {
                foreach ($benchmark->getSubjects() as $subject) {
                    foreach ($subject->getVariants() as $variant) {
                        foreach ($variant->getIterations() as $iteration) {
                            $result = $iteration->getResult(PerfidiousResult::class);

                            // remove original values for now I guess
                            $values = array_diff_key(
                                $result->values,
                                array_flip(array_filter(array_keys($result->values), function (string $key): bool {
                                    return str_ends_with($key, '_raw');
                                }))
                            );

                            $row = array_merge([
                                'iter' => new IntegerNode($iteration->getIndex()),
                                'benchmark' => $benchmark->getName(),
                                'subject' => $subject->getName(),
                                'revs' => $variant->getRevolutions(),
                            ], $values);

                            $rows[] = $row;
                        }
                    }
                }
            }

            $builder->addObject(
                TableBuilder::create()
                    ->addRowsFromArray($rows) /** @phpstan-ignore argument.type */
                    ->build()
            );
        }

        return Reports::fromReport($builder->build());
    }
}
