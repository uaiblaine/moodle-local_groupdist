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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Audit run log tests: the snapshot, the outcomes and the lifecycle.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\local\runlog::class)]
final class runlog_test extends \advanced_testcase {
    /**
     * Build a small course with two groups, three users and one rule.
     *
     * @return array [course, context, distribution].
     */
    private function make_distribution(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);
        foreach (['A', 'A', 'B'] as $city) {
            $user = $generator->create_and_enrol($course);
            $user->city = $city;
            user_update_user($user, false);
        }

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group1->id, (int) $group2->id],
            'affinityrules' => [['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]],
            'seed' => 11,
        ]);
        return [$course, $context, distribution::build($options, $context)];
    }

    /**
     * The snapshot records the run header and one row per candidate with the
     * rule values and the planned group.
     */
    public function test_create_records_snapshot(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $context, $distribution] = $this->make_distribution();

        $runid = runlog::create($distribution, (int) get_admin()->id, $context);

        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);
        $this->assertSame(runlog::STATUS_PENDING, (int) $run->status);
        $this->assertSame(11, (int) $run->seed);
        $this->assertSame($distribution->fingerprint, $run->fingerprint);
        $this->assertSame(3, (int) $run->memberstotal);
        $rules = json_decode($run->rulesjson, true);
        $this->assertSame(ruleset::VERSION, $rules['v']);
        $this->assertSame('city', $rules['rules'][0]['source']);
        $this->assertNotSame('', $rules['rules'][0]['label']);
        $this->assertCount(2, json_decode($run->groupsjson, true));

        $rows = $DB->get_records('local_groupdist_run_user', ['runid' => $runid]);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(runlog::WRITE_PLANNED, (int) $row->writestatus);
            $this->assertGreaterThan(0, (int) $row->groupid);
            $values = json_decode($row->valuesjson, true);
            $this->assertContains($values[0], ['A', 'B']);
        }
    }

    /**
     * Completion marks planned rows written, reported failures failed, and
     * seals the header with the outcome.
     */
    public function test_complete_records_outcomes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $context, $distribution] = $this->make_distribution();
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);

        $rows = array_values($DB->get_records('local_groupdist_run_user', ['runid' => $runid], 'id'));
        $victim = $rows[0];
        runlog::complete($runid, [
            'added' => 2,
            'failed' => 1,
            'failedpairs' => [[(int) $victim->groupid, (int) $victim->userid]],
        ]);

        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);
        $this->assertSame(runlog::STATUS_PARTIAL, (int) $run->status);
        $this->assertSame(2, (int) $run->memberswritten);
        $this->assertGreaterThan(0, (int) $run->timecompleted);

        $this->assertSame(
            runlog::WRITE_FAILED,
            (int) $DB->get_field('local_groupdist_run_user', 'writestatus', ['id' => $victim->id])
        );
        $this->assertSame(2, $DB->count_records('local_groupdist_run_user', [
            'runid' => $runid,
            'writestatus' => runlog::WRITE_WRITTEN,
        ]));
    }

    /**
     * An aborted run is sealed without touching the member rows.
     */
    public function test_abort_seals_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $context, $distribution] = $this->make_distribution();
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);

        runlog::abort($runid);

        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);
        $this->assertSame(runlog::STATUS_ABORTED, (int) $run->status);
        $this->assertSame(3, $DB->count_records('local_groupdist_run_user', [
            'runid' => $runid,
            'writestatus' => runlog::WRITE_PLANNED,
        ]));
    }

    /**
     * Deleting the course purges its audit rows — with a second course as the
     * control proving the purge is scoped.
     */
    public function test_course_deletion_purges_runs(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $distribution] = $this->make_distribution();
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        [, $othercontext, $otherdistribution] = $this->make_distribution();
        $otherrunid = runlog::create($otherdistribution, (int) get_admin()->id, $othercontext);

        delete_course($course, false);

        $this->assertSame(0, $DB->count_records('local_groupdist_run', ['id' => $runid]));
        $this->assertSame(0, $DB->count_records('local_groupdist_run_user', ['runid' => $runid]));
        // Control: the other course's audit survives.
        $this->assertSame(1, $DB->count_records('local_groupdist_run', ['id' => $otherrunid]));
        $this->assertSame(3, $DB->count_records('local_groupdist_run_user', ['runid' => $otherrunid]));
    }

    /**
     * Deleting a user pseudonymises their rows: the rows survive with the
     * userid zeroed and the values blanked, other users untouched (control).
     */
    public function test_user_deletion_pseudonymises_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $context, $distribution] = $this->make_distribution();
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);

        $rows = array_values($DB->get_records('local_groupdist_run_user', ['runid' => $runid], 'id'));
        $victimid = (int) $rows[0]->userid;
        $victim = \core_user::get_user($victimid, '*', MUST_EXIST);

        delete_user($victim);

        $this->assertSame(3, $DB->count_records('local_groupdist_run_user', ['runid' => $runid]));
        $pseudonymised = $DB->get_record('local_groupdist_run_user', ['id' => $rows[0]->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $pseudonymised->userid);
        $this->assertSame('', $pseudonymised->valuesjson);
        // Control: another participant's row is untouched.
        $other = $DB->get_record('local_groupdist_run_user', ['id' => $rows[1]->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $other->userid);
        $this->assertNotSame('', $other->valuesjson);
    }
}
