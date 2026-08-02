<?php

use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$header = <<<'EOF'
Copyright (c) anno Domini nostri Jesu Christi MMXXIV John Boehr & contributors

SPDX-License-Identifier: AGPL-3.0-only WITH romic-exception

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3,
as published by the Free Software Foundation, together with the Romic
Exception (an additional permission under section 7 of that license).

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
and the Romic Exception along with this program.  If not, see
<http://www.gnu.org/licenses/> and the LICENSE_EXCEPTION file.
EOF;

$finder = PhpCsFixer\Finder::create()
    ->files()
    ->name('*.php')
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/stubs',
        __DIR__ . '/tests',
    ]);

return (new PhpCsFixer\Config())
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder)
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'header_comment' => [
            'header' => $header,
            'comment_type' => 'PHPDoc',
            'location' => 'after_open',
            'separate' => 'both',
        ],
    ]);
