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
    public function __construct(
        public readonly string $eventName,
        public readonly int $count,
        public readonly int $timeRunning,
        public readonly int $timeEnabled,
        public readonly int $revolutions,
    ) {
        if ($count < 0) {
            throw new InvalidArgumentException(sprintf('Count cannot be less than zero, got "%s"', $count));
        }

        if ($this->revolutions < 1) {
            throw new InvalidArgumentException(sprintf('Revs cannot be less than zero, got "%s"', $revolutions));
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): ResultInterface
    {
        $eventName = $values['eventName'] ?? throw new InvalidArgumentException();
        $count = $values['count'] ?? throw new InvalidArgumentException();
        $timeRunning = $values['timeRunning'] ?? throw new InvalidArgumentException();
        $timeEnabled = $values['timeEnabled'] ?? throw new InvalidArgumentException();
        $revolutions = $values['revolutions'] ?? throw new InvalidArgumentException();

        assert(is_string($eventName));
        assert(is_integer($count));
        assert(is_integer($timeRunning));
        assert(is_integer($timeEnabled));
        assert(is_integer($revolutions));

        return new self(
            eventName: $eventName,
            count: $count,
            timeRunning: $timeRunning,
            timeEnabled: $timeEnabled,
            revolutions: $revolutions,
        );
    }

    public function getMetrics(): array
    {
        return [
            'eventName' => $this->eventName,
            'count' => $this->count,
            'timeRunning' => $this->timeRunning,
            'timeEnabled' => $this->timeEnabled,
            'revolutions' => $this->revolutions,
        ];
    }

    public function getKey(): string
    {
        return 'perfidious_' . preg_replace('~[^\w\d]+~', '_', $this->eventName);
    }
}
