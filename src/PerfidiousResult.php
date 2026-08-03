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

class PerfidiousResult implements ResultInterface
{
    /**
     * @param array<string, int|float> $values
     */
    public function __construct(
        public readonly int $timeRunning,
        public readonly int $timeEnabled,
        public readonly int $revolutions,
        public readonly array $values,
    ) {
        if ($this->revolutions < 1) {
            throw new InvalidArgumentException(sprintf('Revs cannot be less than zero, got "%s"', $revolutions));
        }
    }

    /**
     * @param array<string, int|float> $rawValues
     */
    public static function create(
        int $timeRunning,
        int $timeEnabled,
        int $revolutions,
        array $rawValues,
    ): self {
        $values = [];
        /** @var array<string, string> $sanitizedOwners */
        $sanitizedOwners = [];
        /** @var array<string, ?string> $metricOwners */
        $metricOwners = [
            'timeRunning' => null,
            'timeEnabled' => null,
            'revolutions' => null,
        ];

        foreach ($rawValues as $eventName => $value) {
            $sanitized = self::sanitizeEventName($eventName);
            if ('' === $sanitized) {
                throw new InvalidArgumentException(sprintf('Event name "%s" produces an empty metric key', $eventName));
            }

            if (isset($sanitizedOwners[$sanitized])) {
                throw new InvalidArgumentException(sprintf(
                    'Event names "%s" and "%s" both sanitize to metric key "%s"',
                    $sanitizedOwners[$sanitized],
                    $eventName,
                    $sanitized,
                ));
            }

            foreach ([$sanitized . '_raw', $sanitized] as $metricKey) {
                if (!array_key_exists($metricKey, $metricOwners)) {
                    continue;
                }

                $owner = $metricOwners[$metricKey];
                if (null === $owner) {
                    throw new InvalidArgumentException(sprintf(
                        'Event name "%s" produces metric key "%s", which is reserved for result metadata',
                        $eventName,
                        $metricKey,
                    ));
                }

                throw new InvalidArgumentException(sprintf(
                    'Event names "%s" and "%s" produce conflicting metric key "%s"',
                    $owner,
                    $eventName,
                    $metricKey,
                ));
            }

            $sanitizedOwners[$sanitized] = $eventName;
            $metricOwners[$sanitized . '_raw'] = $eventName;
            $metricOwners[$sanitized] = $eventName;
            $values[$sanitized . '_raw'] = $value;
            $values[$sanitized] = $value * $timeEnabled / $timeRunning / $revolutions;
        }

        return new self(
            timeRunning: $timeRunning,
            timeEnabled: $timeEnabled,
            revolutions: $revolutions,
            values: $values,
        );
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $timeRunning = self::requireNumericValue($values, 'timeRunning');
        $timeEnabled = self::requireNumericValue($values, 'timeEnabled');
        $revolutions = self::requireNumericValue($values, 'revolutions');

        $arr = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Perfidious result keys must be strings, got %s key',
                    get_debug_type($key),
                ));
            }

            if (in_array($key, ['timeRunning', 'timeEnabled', 'revolutions'], true)) {
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $arr[$key] = $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $arr[$key] = str_contains($value, '.') ? (float) $value : (int) $value;
            } else {
                throw new InvalidArgumentException(sprintf(
                    'Perfidious result value "%s" must be numeric, got %s',
                    $key,
                    get_debug_type($value),
                ));
            }
        }

        return new self(
            timeRunning: (int) $timeRunning,
            timeEnabled: (int) $timeEnabled,
            revolutions: (int) $revolutions,
            values: $arr,
        );
    }

    public function getMetrics(): array
    {
        return array_merge([
            'timeRunning' => $this->timeRunning,
            'timeEnabled' => $this->timeEnabled,
            'revolutions' => $this->revolutions,
        ], $this->values);
    }

    public function getKey(): string
    {
        return 'perfidious';
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function requireNumericValue(array $values, string $key): int|float|string
    {
        if (!array_key_exists($key, $values)) {
            throw new InvalidArgumentException(sprintf('Perfidious result is missing required value "%s"', $key));
        }

        $value = $values[$key];
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException(sprintf(
                'Perfidious result value "%s" must be numeric, got %s',
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private static function sanitizeEventName(string $eventName): string
    {
        $eventName = str_replace('::', '__', $eventName);
        $sanitized = preg_replace('/[^\w\d]+/', '-', $eventName);
        if (!is_string($sanitized)) {
            throw new InvalidArgumentException(sprintf('Failed to sanitize event name "%s"', $eventName));
        }
        return trim($sanitized, '-');
    }
}
