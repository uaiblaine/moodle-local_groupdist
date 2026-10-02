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

use local_groupdist\local\applier;
use local_groupdist\local\distribution;
use local_groupdist\local\options;
use local_groupdist\local\runlog;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * The distribution_applied event through a course log restore.
 *
 * PHPUnit metadata is written as docblock tags, never attributes: Moodle 4.5
 * runs PHPUnit 9, which reads only the tags, and its moodle-cs reports every
 * test of a class without a covers tag.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\event\distribution_applied
 */
final class distribution_applied_test extends \advanced_testcase {
    /**
     * A restored log row of the event points at the restored run, and the
     * restore raises no missing-mapping debugging for this event.
     *
     * Changes that must make it fail: removing get_objectid_mapping() (the
     * row keeps the source run's id, with a debugging notice) or
     * get_other_mapping() (a debugging notice).
     *
     * @return void
     */
    public function test_a_restored_log_points_at_the_restored_run(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        // Log writes wait for the transaction to commit.
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_and_enrol($course);
        $generator->create_and_enrol($course);
        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'seed' => 21,
        ]);
        $distribution = distribution::build($options, $context);
        $runid = runlog::create($distribution, (int) $USER->id, $context);
        runlog::complete($runid, applier::apply($distribution, null, $runid));

        $eventname = '\\' . distribution_applied::class;
        $log = $DB->get_record('logstore_standard_log', ['eventname' => $eventname, 'courseid' => $course->id], '*', MUST_EXIST);
        // The precondition: the source row points at the source run.
        $this->assertSame($runid, (int) $log->objectid);

        $newcourseid = $this->backup_and_restore($course);

        $newrun = $DB->get_record('local_groupdist_run', ['courseid' => $newcourseid], '*', MUST_EXIST);
        // Control: the restored run is a new row, so equal ids below mean a mapping, not a copy.
        $this->assertNotSame($runid, (int) $newrun->id);
        $restored = $DB->get_record(
            'logstore_standard_log',
            ['eventname' => $eventname, 'courseid' => $newcourseid],
            '*',
            MUST_EXIST
        );
        $this->assertSame((int) $newrun->id, (int) $restored->objectid);

        $ours = array_filter($this->getDebuggingMessages(), static function ($debugging): bool {
            return str_contains($debugging->message, 'distribution_applied');
        });
        $this->assertSame([], array_values($ours));
        $this->resetDebugging();

        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
    }

    /**
     * Back a course up with users and logs, and restore it into a new course.
     *
     * Same settings as core's logstore_standard store_test::test_backup_restore().
     *
     * @param \stdClass $course The course.
     * @return int The new course id.
     */
    private function backup_and_restore(\stdClass $course): int {
        global $CFG, $USER;

        // Turn off file logging, otherwise it can't delete the file (Windows).
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        // Import mode writes a plain directory rather than a zip.
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->get_plan()->get_setting('logs')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course($course->fullname . '_r', $course->shortname . '_r', $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $rc->get_plan()->get_setting('logs')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return (int) $newcourseid;
    }
}
