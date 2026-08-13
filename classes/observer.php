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

namespace local_groupdist;

/**
 * Event observers keeping the audit log's lifecycle honest.
 *
 * Course deletion purges the course's runs — the recycle bin stores a backup
 * file, not the course, so the rows would be unreachable orphans (restoring
 * creates a new course; the audit travels in the backup only when course logs
 * are included). User deletion pseudonymises instead of deleting, so run
 * counts and group compositions stay intact.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Purge the audit log of a deleted course.
     *
     * @param \core\event\course_deleted $event The event.
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        local\runlog::purge_course((int) $event->objectid);
    }

    /**
     * Pseudonymise a deleted user's audit rows.
     *
     * @param \core\event\user_deleted $event The event.
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        local\runlog::pseudonymise_user((int) $event->objectid);
    }
}
