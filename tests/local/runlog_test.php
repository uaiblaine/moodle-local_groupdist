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
            \core\user::update_user($user, false);
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
        $this->assertSame(0, (int) $run->memberswritten);
        $this->assertSame(3, $DB->count_records('local_groupdist_run_user', [
            'runid' => $runid,
            'writestatus' => runlog::WRITE_PLANNED,
        ]));
    }

    /**
     * An abort after an interrupted attempt records what that attempt wrote:
     * the planned membership stamped with this run's seed becomes written and
     * is counted, while the same membership added any other way stays planned.
     *
     * Changes that must make it fail: dropping the reconciliation from
     * abort(), or either half of its stamp test (component, itemid = seed).
     *
     * @return void
     */
    public function test_abort_records_memberships_an_earlier_attempt_wrote(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $context, $distribution] = $this->make_distribution();
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);

        $rows = array_values($DB->get_records('local_groupdist_run_user', ['runid' => $runid], 'id'));
        $this->assertCount(3, $rows);
        [$stamped, $othercomponent, $otherseed] = $rows;
        // This run's stamp, as applier::apply() writes it.
        groups_add_member((int) $stamped->groupid, (int) $stamped->userid, 'local_groupdist', 11);
        /* Another component's stamp that happens to carry the same item id.
           A plain manual add needs no control of its own: core stores it with
           itemid 0, which the seed test already excludes. */
        groups_add_member((int) $othercomponent->groupid, (int) $othercomponent->userid, 'enrol_self', 11);
        // Another run's stamp (a different seed).
        groups_add_member((int) $otherseed->groupid, (int) $otherseed->userid, 'local_groupdist', 12);

        $this->assertSame(1, runlog::abort($runid));

        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);
        $this->assertSame(runlog::STATUS_ABORTED, (int) $run->status);
        $this->assertSame(1, (int) $run->memberswritten);
        $status = $DB->get_records_menu('local_groupdist_run_user', ['runid' => $runid], '', 'id, writestatus');
        $this->assertSame(runlog::WRITE_WRITTEN, (int) $status[$stamped->id]);
        $this->assertSame(runlog::WRITE_PLANNED, (int) $status[$othercomponent->id]);
        $this->assertSame(runlog::WRITE_PLANNED, (int) $status[$otherseed->id]);
    }

    /**
     * Aborting a run that no longer exists returns 0 instead of throwing: the
     * adhoc task's stale branch calls it and must not throw.
     *
     * @return void
     */
    public function test_abort_of_a_missing_run_does_not_throw(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $context, $distribution] = $this->make_distribution();
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        $DB->delete_records('local_groupdist_run', ['id' => $runid]);

        $this->assertSame(0, runlog::abort($runid));
        $this->assertSame(0, runlog::abort(0));
    }

    /**
     * Only a completed run marks a course and seed as applied: a pending run
     * (an interrupted inline apply) and an aborted one do not.
     *
     * @return void
     */
    public function test_is_applied_counts_only_a_completed_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $distribution] = $this->make_distribution();
        $courseid = (int) $course->id;
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);

        $this->assertFalse(runlog::is_applied($courseid, 11), 'A pending run counted as applied.');
        $DB->set_field('local_groupdist_run', 'status', runlog::STATUS_ABORTED, ['id' => $runid]);
        $this->assertFalse(runlog::is_applied($courseid, 11), 'An aborted run counted as applied.');

        runlog::complete($runid, ['added' => 3, 'failed' => 0]);
        $this->assertTrue(runlog::is_applied($courseid, 11));
        // Controls: the same run says nothing about another seed or course.
        $this->assertFalse(runlog::is_applied($courseid, 12));
        $this->assertFalse(runlog::is_applied($courseid + 1, 11));
    }

    /**
     * A seed is spent once a run under it wrote memberships: completed,
     * partial, or aborted after an earlier attempt wrote. A pending run and an
     * aborted run that wrote nothing leave it reusable; a pending run that
     * wrote is test_is_seed_spent_counts_a_pending_run_that_wrote().
     *
     * Changes that must make it fail: dropping any status arm of
     * is_seed_spent(), or its memberswritten test on the aborted arm.
     *
     * @return void
     */
    public function test_is_seed_spent_counts_only_runs_that_wrote(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $distribution] = $this->make_distribution();
        $courseid = (int) $course->id;
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        $set = function (int $status, int $written) use ($DB, $runid): void {
            $DB->update_record('local_groupdist_run', (object) [
                'id' => $runid,
                'status' => $status,
                'memberswritten' => $written,
            ]);
        };

        $this->assertFalse(runlog::is_seed_spent($courseid, 11), 'A pending run spent its seed.');
        $set(runlog::STATUS_ABORTED, 0);
        $this->assertFalse(runlog::is_seed_spent($courseid, 11), 'An aborted run that wrote nothing spent its seed.');
        $set(runlog::STATUS_ABORTED, 2);
        $this->assertTrue(runlog::is_seed_spent($courseid, 11), 'An aborted run that wrote left its seed reusable.');
        $set(runlog::STATUS_PARTIAL, 2);
        $this->assertTrue(runlog::is_seed_spent($courseid, 11), 'A partial run left its seed reusable.');
        $set(runlog::STATUS_COMPLETED, 3);
        $this->assertTrue(runlog::is_seed_spent($courseid, 11), 'A completed run left its seed reusable.');
        // Controls: the same run says nothing about another seed or course.
        $this->assertFalse(runlog::is_seed_spent($courseid, 12));
        $this->assertFalse(runlog::is_seed_spent($courseid + 1, 11));
    }

    /**
     * A pending run spends its seed once a membership in the course carries
     * that seed's stamp, as an interrupted inline apply or a task that died
     * leaves it: memberswritten is still 0, so only the stamp tells.
     *
     * Each stamp added before the last one is a control that must not count:
     * another component with the same item id, another seed, and the same
     * stamp in another course. Changes that must make it fail: dropping the
     * membership check from is_seed_spent(), or its component, item id or
     * course test.
     *
     * @return void
     */
    public function test_is_seed_spent_counts_a_pending_run_that_wrote(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $distribution] = $this->make_distribution();
        $courseid = (int) $course->id;
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        [$othercomponent, $otherseed, $stamped] = array_values(
            $DB->get_records('local_groupdist_run_user', ['runid' => $runid], 'id')
        );
        $generator = $this->getDataGenerator();
        $othercourse = $generator->create_course();
        $othergroup = (int) $generator->create_group(['courseid' => $othercourse->id])->id;
        $outsider = (int) $generator->create_and_enrol($othercourse)->id;

        $this->assertFalse(runlog::is_seed_spent($courseid, 11), 'A pending run that wrote nothing spent its seed.');
        groups_add_member((int) $othercomponent->groupid, (int) $othercomponent->userid, 'enrol_self', 11);
        $this->assertFalse(runlog::is_seed_spent($courseid, 11), "Another component's stamp spent the seed.");
        groups_add_member((int) $otherseed->groupid, (int) $otherseed->userid, 'local_groupdist', 12);
        $this->assertFalse(runlog::is_seed_spent($courseid, 11), "Another seed's stamp spent the seed.");
        groups_add_member($othergroup, $outsider, 'local_groupdist', 11);
        $this->assertFalse(runlog::is_seed_spent($courseid, 11), 'A stamp in another course spent the seed.');

        // What an interrupted apply of this run leaves behind.
        groups_add_member((int) $stamped->groupid, (int) $stamped->userid, 'local_groupdist', 11);
        $this->assertSame(runlog::STATUS_PENDING, (int) $DB->get_field('local_groupdist_run', 'status', ['id' => $runid]));
        $this->assertTrue(runlog::is_seed_spent($courseid, 11), 'A pending run that wrote left its seed reusable.');
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
