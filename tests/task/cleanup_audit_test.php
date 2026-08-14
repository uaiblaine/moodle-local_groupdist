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

namespace local_groupdist\task;

use local_groupdist\local\distribution;
use local_groupdist\local\options;
use local_groupdist\local\runlog;

/**
 * Retention sweep tests.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\task\cleanup_audit
 */
final class cleanup_audit_test extends \advanced_testcase {
    /**
     * Seed two runs, one aged past retention.
     *
     * @return array [oldrunid, recentrunid].
     */
    private function seed_runs(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_and_enrol($course);

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'seed' => 5,
        ]);
        $distribution = distribution::build($options, $context);
        $oldrunid = runlog::create($distribution, (int) get_admin()->id, $context);
        $recentrunid = runlog::create($distribution, (int) get_admin()->id, $context);
        $DB->set_field('local_groupdist_run', 'timecreated', time() - 400 * DAYSECS, ['id' => $oldrunid]);
        return [$oldrunid, $recentrunid];
    }

    /**
     * Expired runs are removed with their member rows; recent runs survive.
     */
    public function test_expired_runs_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$oldrunid, $recentrunid] = $this->seed_runs();
        set_config('auditretentiondays', 365, 'local_groupdist');

        $this->expectOutputRegex('/retention sweep removed 1/');
        (new cleanup_audit())->execute();

        $this->assertSame(0, $DB->count_records('local_groupdist_run', ['id' => $oldrunid]));
        $this->assertSame(0, $DB->count_records('local_groupdist_run_user', ['runid' => $oldrunid]));
        // Control: the recent run survives with its member rows.
        $this->assertSame(1, $DB->count_records('local_groupdist_run', ['id' => $recentrunid]));
        $this->assertSame(1, $DB->count_records('local_groupdist_run_user', ['runid' => $recentrunid]));
    }

    /**
     * Retention 0 keeps everything (the sweep proves it ran by the control above).
     */
    public function test_retention_zero_keeps_forever(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$oldrunid] = $this->seed_runs();
        set_config('auditretentiondays', 0, 'local_groupdist');

        (new cleanup_audit())->execute();

        $this->assertSame(1, $DB->count_records('local_groupdist_run', ['id' => $oldrunid]));
    }
}
