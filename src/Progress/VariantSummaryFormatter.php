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

namespace jbboehr\PhpBenchPerfidious\Progress;

use PhpBench\Assertion\ParameterProvider;
use PhpBench\Expression\ExpressionLanguage;
use PhpBench\Expression\Printer\EvaluatingPrinter;
use PhpBench\Model\Variant;
use PhpBench\Progress\VariantFormatter;
use PhpBench\Progress\VariantSummaryFormatter as BaseVariantSummaryFormatter;

final class VariantSummaryFormatter implements VariantFormatter
{
    public const DEFAULT_FORMAT = BaseVariantSummaryFormatter::DEFAULT_FORMAT . ' ~ " " ~ ' . <<<'EOT'
label("Instr") ~ mode(variant.perfidious.perf__PERF_COUNT_HW_INSTRUCTIONS) ~
" (" ~ rstdev(variant.perfidious.perf__PERF_COUNT_HW_INSTRUCTIONS) ~ ")"
EOT;

    public const BASELINE_FORMAT = BaseVariantSummaryFormatter::BASELINE_FORMAT . ' ~ " " ~ ' . <<<'EOT'
"[" ~
label("Instr") ~ mode(variant.perfidious.perf__PERF_COUNT_HW_INSTRUCTIONS) ~
" vs. " ~
label("Instr") ~ mode(baseline.perfidious.perf__PERF_COUNT_HW_INSTRUCTIONS) ~ "] " ~
percent_diff(
    mode(baseline.perfidious.perf__PERF_COUNT_HW_INSTRUCTIONS),
    mode(variant.perfidious.perf__PERF_COUNT_HW_INSTRUCTIONS),
    (rstdev(variant.perfidious.perf__PERF_COUNT_HW_INSTRUCTIONS) * 2)
) ~
" (" ~ rstdev(variant.perfidious.perf__PERF_COUNT_HW_INSTRUCTIONS) ~ ")"
EOT
    ;

    private VariantFormatter $innerFormatter;

    public function __construct(
        ExpressionLanguage $parser,
        EvaluatingPrinter $printer,
        ParameterProvider $paramProvider,
        ?string $format = self::DEFAULT_FORMAT,
        ?string $baselineFormat = self::BASELINE_FORMAT
    ) {
        $this->innerFormatter = new BaseVariantSummaryFormatter(
            $parser,
            $printer,
            $paramProvider,
            $format ?? self::DEFAULT_FORMAT,
            $baselineFormat ?? self::BASELINE_FORMAT,
        );
    }

    public function formatVariant(Variant $variant): string
    {
        $tmp = $this->innerFormatter->formatVariant($variant);
        return $tmp;
    }
}
