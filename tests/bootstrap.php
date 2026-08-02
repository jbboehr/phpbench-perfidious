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

$errorReporting = error_reporting();

// Infection 0.28 supports PHP 8.1 but loads Safe 2.x function wrappers that
// trigger engine deprecations on PHP 8.4+. Keep that dev-only dependency from
// polluting PHPBench subprocess output, while restoring full reporting before
// any benchmark code runs.
error_reporting($errorReporting & ~E_DEPRECATED);

try {
    return require dirname(__DIR__) . '/vendor/autoload.php';
} finally {
    error_reporting($errorReporting);
}
