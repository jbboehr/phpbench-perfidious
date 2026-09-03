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

namespace jbboehr\PhpBenchPerfidious;

use InvalidArgumentException;
use PhpBench\Model\ResultInterface;

final class SamplerResult implements ResultInterface
{
    /**
     * @param array<string, int|float> $values
     */
    private function __construct(
        public readonly int $elapsedTimeNs,
        public readonly int $revolutions,
        public readonly array $values,
    ) {
    }

    private static function validateMetadata(int $elapsedTimeNs, int $revolutions): void
    {
        if ($elapsedTimeNs < 0) {
            throw new InvalidArgumentException(sprintf(
                'Elapsed time cannot be negative, got "%s"',
                $elapsedTimeNs,
            ));
        }
        if ($revolutions < 1) {
            throw new InvalidArgumentException(sprintf(
                'Revolutions must be at least one, got "%s"',
                $revolutions,
            ));
        }
    }

    /**
     * @param array<string, int> $rawValues
     */
    public static function create(int $elapsedTimeNs, int $revolutions, array $rawValues): self
    {
        return self::createFromUncheckedRawValues($elapsedTimeNs, $revolutions, $rawValues);
    }

    /**
     * PHP does not enforce array key and value types from PHPDoc at runtime.
     *
     * @param array<array-key, mixed> $rawValues
     */
    private static function createFromUncheckedRawValues(
        int $elapsedTimeNs,
        int $revolutions,
        array $rawValues,
    ): self {
        self::validateMetadata($elapsedTimeNs, $revolutions);

        $values = [];
        /** @var array<string, ?string> $metricOwners */
        $metricOwners = [
            'elapsedTimeNs' => null,
            'revolutions' => null,
        ];

        foreach ($rawValues as $metric => $value) {
            if (!is_string($metric)) {
                throw new InvalidArgumentException(sprintf(
                    'Sampler metric names must be strings, got %s key',
                    get_debug_type($metric),
                ));
            }
            if (!is_int($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Sampler metric "%s" must be an integer, got %s',
                    $metric,
                    get_debug_type($value),
                ));
            }

            $key = str_replace('-', '_', $metric);
            if ('' === $key) {
                throw new InvalidArgumentException('Sampler metric names cannot be empty');
            }
            if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $key)) {
                throw new InvalidArgumentException(sprintf(
                    'Metric "%s" produces key "%s", which cannot be serialized by PhpBench',
                    $metric,
                    $key,
                ));
            }
            if (str_ends_with($key, '_raw')) {
                throw new InvalidArgumentException(sprintf(
                    'Metric "%s" produces key "%s", which uses the reserved suffix "_raw"',
                    $metric,
                    $key,
                ));
            }
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf(
                    'Sampler metric "%s" cannot be negative, got "%s"',
                    $metric,
                    $value,
                ));
            }

            foreach ([$key . '_raw', $key] as $metricKey) {
                if (!array_key_exists($metricKey, $metricOwners)) {
                    continue;
                }

                $owner = $metricOwners[$metricKey];
                if (null === $owner) {
                    throw new InvalidArgumentException(sprintf(
                        'Metric "%s" produces key "%s", which is reserved for result metadata',
                        $metric,
                        $metricKey,
                    ));
                }

                throw new InvalidArgumentException(sprintf(
                    'Metrics "%s" and "%s" produce conflicting key "%s"',
                    $owner,
                    $metric,
                    $metricKey,
                ));
            }

            $metricOwners[$key . '_raw'] = $metric;
            $metricOwners[$key] = $metric;
            $values[$key . '_raw'] = $value;
            $values[$key] = $value / $revolutions;
        }

        return new self($elapsedTimeNs, $revolutions, $values);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $elapsedTimeNs = self::requireIntegerValue($values, 'elapsedTimeNs');
        $revolutions = self::requireIntegerValue($values, 'revolutions');
        $metrics = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Sampler result metric names must be strings, got %s key',
                    get_debug_type($key),
                ));
            }
            if (in_array($key, ['elapsedTimeNs', 'revolutions'], true)) {
                continue;
            }

            $metrics[$key] = str_ends_with($key, '_raw')
                ? self::parseRawMetricValue($value, $key)
                : self::parseNumericValue($value, $key);
        }

        self::validateMetadata($elapsedTimeNs, $revolutions);

        return new self($elapsedTimeNs, $revolutions, $metrics);
    }

    public function getMetrics(): array
    {
        return array_merge([
            'elapsedTimeNs' => $this->elapsedTimeNs,
            'revolutions' => $this->revolutions,
        ], $this->values);
    }

    public function getKey(): string
    {
        return 'perfidious_sampler';
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function requireIntegerValue(array $values, string $key): int
    {
        if (!array_key_exists($key, $values)) {
            throw new InvalidArgumentException(sprintf('Sampler result is missing required value "%s"', $key));
        }

        $value = $values[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $parsed = self::parseIntegerString($value);
            if (null !== $parsed) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Sampler result value "%s" must be an integer, got %s',
            $key,
            get_debug_type($value),
        ));
    }

    private static function parseRawMetricValue(mixed $value, string $key): int
    {
        $parsed = is_int($value)
            ? $value
            : (is_string($value) ? self::parseIntegerString($value) : null);

        if (null === $parsed) {
            throw new InvalidArgumentException(sprintf(
                'Sampler result raw metric "%s" must be an integer, got %s',
                $key,
                get_debug_type($value),
            ));
        }
        if ($parsed < 0) {
            throw new InvalidArgumentException(sprintf(
                'Sampler result metric "%s" cannot be negative, got "%s"',
                $key,
                $parsed,
            ));
        }

        return $parsed;
    }

    private static function parseNumericValue(mixed $value, string $key): int|float
    {
        $parsed = null;

        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_float($value)) {
            if (is_finite($value)) {
                $parsed = $value;
            }
        } elseif (is_string($value)) {
            $integer = self::parseIntegerString($value);
            if (null !== $integer) {
                $parsed = $integer;
            } elseif (is_numeric($value)) {
                $float = (float) $value;
                if (is_finite($float) && (0.0 !== $float || self::isZeroNumericString($value))) {
                    $parsed = $float;
                }
            }
        }

        if (null === $parsed) {
            throw new InvalidArgumentException(sprintf(
                'Sampler result metric "%s" must be a finite number, got %s',
                $key,
                get_debug_type($value),
            ));
        }
        if ($parsed < 0) {
            throw new InvalidArgumentException(sprintf(
                'Sampler result metric "%s" cannot be negative, got "%s"',
                $key,
                $parsed,
            ));
        }

        return $parsed;
    }

    private static function isZeroNumericString(string $value): bool
    {
        $significand = substr($value, 0, strcspn($value, 'eE'));

        return false === strpbrk($significand, '123456789');
    }

    private static function parseIntegerString(string $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return false !== $parsed && (string) $parsed === $value ? $parsed : null;
    }
}
