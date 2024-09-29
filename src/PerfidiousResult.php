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

        foreach ($rawValues as $key => $value) {
            $key = self::sanitizeEventName($key);
            $values[$key . '_orig'] = $value;
            $values[$key] = $value * $timeEnabled / $timeRunning;
        }

        return new self(
            timeRunning: $timeRunning,
            timeEnabled: $timeEnabled,
            revolutions: $revolutions,
            values: $values,
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): ResultInterface
    {
        $timeRunning = $values['timeRunning'] ?? throw new InvalidArgumentException();
        $timeEnabled = $values['timeEnabled'] ?? throw new InvalidArgumentException();
        $revolutions = $values['revolutions'] ?? throw new InvalidArgumentException();

        assert(is_numeric($timeRunning));
        assert(is_numeric($timeEnabled));
        assert(is_numeric($revolutions));

        $arr = [];

        foreach ($values as $key => $value) {
            if (!in_array($key, ['timeRunning', 'timeEnabled', 'revolutions']) && is_numeric($value)) {
                $arr[$key] = (int) $value;
            } elseif (is_string($value)) {
                if (str_contains($value, '.')) {
                    $arr[$key] = (float) $value;
                } else {
                    $arr[$key] = (int) $value;
                }
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

    private static function sanitizeEventName(string $eventName): string
    {
        $eventName = str_replace('::', '__', $eventName);
        $eventName = preg_replace('/[^\w\d]+/', '-', $eventName);
        assert(is_string($eventName));
        return trim($eventName, '-');
    }
}
