<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_groupdist\local;

/**
 * Result of one allocation run.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class allocation {
    /** @var array Map of groupid => list of allocated user ids, in allocation order. */
    public array $assignments = [];

    /** @var array User ids that could not be placed (no capacity left). */
    public array $unassigned = [];

    /**
     * @var array Typed warnings: each an array with 'type' (an allocator::WARNING_*
     *   constant) plus type-specific keys ('value', 'count').
     */
    public array $warnings = [];

    /**
     * Total number of memberships this allocation would create.
     *
     * @return int The count.
     */
    public function count_memberships(): int {
        return array_sum(array_map('count', $this->assignments));
    }
}
