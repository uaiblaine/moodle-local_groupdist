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

namespace local_groupdist\event;

/**
 * Event fired once per applied distribution run.
 *
 * Individual memberships already produce core group_member_added events; this
 * one records the run itself (seed, group count, memberships written). No
 * objectid/objecttable: the plugin owns no table for runs.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class distribution_applied extends \core\event\base {
    /**
     * Initialise the event.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string The name.
     */
    public static function get_name(): string {
        return get_string('event_distribution_applied', 'local_groupdist');
    }

    /**
     * Non-localised description for the log.
     *
     * @return string The description.
     */
    public function get_description(): string {
        $memberships = $this->other['memberships'] ?? 0;
        $groupcount = $this->other['groupcount'] ?? 0;
        return "The user with id '{$this->userid}' distributed participants into '{$groupcount}' groups "
            . "of the course with id '{$this->courseid}', creating '{$memberships}' memberships.";
    }

    /**
     * Relevant URL.
     *
     * @return \moodle_url The group management page of the course.
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/group/index.php', ['id' => $this->courseid]);
    }
}
