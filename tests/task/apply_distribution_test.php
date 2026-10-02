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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Adhoc apply task tests, most importantly the fingerprint staleness guard.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\task\apply_distribution::class)]
final class apply_distribution_test extends \advanced_testcase {
    /**
     * Prepare a course with one group and users, returning options + fingerprint.
     *
     * @return array [course, context, group, options, fingerprint, runid].
     */
    private function make_plan(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_and_enrol($course);
        $generator->create_and_enrol($course);

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'seed' => 11,
        ]);
        $distribution = distribution::build($options, $context);
        $runid = \local_groupdist\local\runlog::create($distribution, (int) get_admin()->id, $context);
        return [$course, $context, $group, $options, $distribution->fingerprint, $runid];
    }

    /**
     * A matching fingerprint writes the memberships and fills the task's
     * progress bar.
     */
    public function test_execute_applies_on_matching_fingerprint(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, , $group, $options, $fingerprint, $runid] = $this->make_plan();

        $task = apply_distribution::create($options, $fingerprint, $runid);
        $task->set_userid(get_admin()->id);
        $taskid = \core\task\manager::queue_adhoc_task($task);
        $task->set_id($taskid);
        $task->initialise_progress();

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/applied distribution/');
        $task->execute();

        $this->assertSame(2, $DB->count_records('groups_members', ['groupid' => $group->id]));
        // The owner is told the background run finished.
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertSame('applyresult', $messages[0]->eventtype);
        // The stored progress bar the status page polls was moved to the end.
        $idnumber = \core\output\stored_progress_bar::convert_to_idnumber(apply_distribution::class, $taskid);
        $this->assertEquals(100, $DB->get_field('stored_progress', 'percentcompleted', ['idnumber' => $idnumber]));
    }

    /**
     * Initialising a queued task's progress stores its bar as pending where
     * core supports a pending bar, so the status page shows it before the task
     * starts; where core has no pending state, no row exists until then.
     *
     * The precondition is that queueing alone stores nothing. Changes that
     * must make it fail: initialise_progress() no longer calling core's
     * initialise_stored_progress() where it exists.
     *
     * @return void
     */
    public function test_initialise_progress_records_a_pending_bar_where_core_can(): void {
        global $DB;
        $this->resetAfterTest();
        [, , , $options, $fingerprint, $runid] = $this->make_plan();

        $task = apply_distribution::create($options, $fingerprint, $runid);
        $task->set_userid(get_admin()->id);
        $taskid = \core\task\manager::queue_adhoc_task($task);
        $task->set_id($taskid);
        $idnumber = \core\output\stored_progress_bar::convert_to_idnumber(apply_distribution::class, $taskid);
        $this->assertFalse($DB->record_exists('stored_progress', ['idnumber' => $idnumber]));

        $task->initialise_progress();

        $this->assertSame(
            method_exists($task, 'initialise_stored_progress'),
            $DB->record_exists('stored_progress', ['idnumber' => $idnumber])
        );
    }

    /**
     * The staleness guard: a fingerprint mismatch writes nothing.
     * test_execute_applies_on_matching_fingerprint() is the control: the same
     * plan does write when nothing changed.
     */
    public function test_execute_refuses_stale_fingerprint(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , $group, $options, $fingerprint, $runid] = $this->make_plan();

        // The world changes after the preview: a new enrolment.
        $this->getDataGenerator()->create_and_enrol($course);

        $task = apply_distribution::create($options, $fingerprint, $runid);
        $task->set_userid(get_admin()->id);
        $taskid = \core\task\manager::queue_adhoc_task($task);
        $task->set_id($taskid);
        $task->initialise_progress();

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/fingerprint mismatch/');
        $task->execute();

        $this->assertSame(0, $DB->count_records('groups_members', ['groupid' => $group->id]));
        // The abort is not silent: the owner receives a notification.
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertSame(get_string('applymessagestalebody', 'local_groupdist'), $messages[0]->fullmessage);
        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);
        $this->assertSame(\local_groupdist\local\runlog::STATUS_ABORTED, (int) $run->status);
        $this->assertSame(0, (int) $run->memberswritten);
    }

    /**
     * A retried task that turns stale after an earlier attempt wrote part of
     * the plan keeps those memberships, and says so: the run records them as
     * written and the owner is not told that nothing was written.
     * test_execute_refuses_stale_fingerprint() is the control with no
     * earlier write.
     *
     * @return void
     */
    public function test_execute_abort_reports_an_earlier_attempts_writes(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $group, $options, $fingerprint, $runid] = $this->make_plan();

        // The first attempt wrote one membership, then died.
        $plan = distribution::build($options, $context);
        $firstuser = (int) $plan->allocation->assignments[(int) $group->id][0];
        groups_add_member((int) $group->id, $firstuser, 'local_groupdist', $options->seed);
        // Then the world changes before the retry.
        $this->getDataGenerator()->create_and_enrol($course);

        $task = apply_distribution::create($options, $fingerprint, $runid);
        $task->set_userid(get_admin()->id);
        $taskid = \core\task\manager::queue_adhoc_task($task);
        $task->set_id($taskid);
        $task->initialise_progress();

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/fingerprint mismatch.*1 memberships from an earlier attempt were kept/');
        $task->execute();
        $messages = $sink->get_messages();
        $sink->close();

        // Nothing more was written.
        $this->assertSame(1, $DB->count_records('groups_members', ['groupid' => $group->id]));
        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);
        $this->assertSame(\local_groupdist\local\runlog::STATUS_ABORTED, (int) $run->status);
        $this->assertSame(1, (int) $run->memberswritten);
        $this->assertSame(
            \local_groupdist\local\runlog::WRITE_WRITTEN,
            (int) $DB->get_field('local_groupdist_run_user', 'writestatus', ['runid' => $runid, 'userid' => $firstuser])
        );
        $this->assertCount(1, $messages);
        $this->assertSame(get_string('applymessagestalepartialbody', 'local_groupdist', 1), $messages[0]->fullmessage);
    }

    /**
     * An interrupted run resumes: its own partial writes (stamped with the
     * seed) are invisible to the recompute, so the fingerprint still matches
     * and the remainder is applied idempotently.
     *
     * Those writes also spend the seed for any new plan, which the task must
     * not consult: the precondition shows the seed is spent, and the run still
     * completes.
     */
    public function test_execute_resumes_after_partial_write(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $group, $options, $fingerprint, $runid] = $this->make_plan();

        // Simulate the first attempt dying after one membership.
        $plan = distribution::build($options, $context);
        $firstuser = $plan->allocation->assignments[(int) $group->id][0];
        groups_add_member((int) $group->id, $firstuser, 'local_groupdist', $options->seed);
        $this->assertTrue(\local_groupdist\local\runlog::is_seed_spent((int) $course->id, $options->seed));

        $task = apply_distribution::create($options, $fingerprint, $runid);
        $task->set_userid(get_admin()->id);
        $taskid = \core\task\manager::queue_adhoc_task($task);
        $task->set_id($taskid);
        $task->initialise_progress();

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/applied distribution/');
        $task->execute();
        $sink->close();

        $this->assertSame(2, $DB->count_records('groups_members', ['groupid' => $group->id]));
        $this->assertSame(
            \local_groupdist\local\runlog::STATUS_COMPLETED,
            (int) $DB->get_field('local_groupdist_run', 'status', ['id' => $runid])
        );
    }

    /**
     * The course lookup helper finds only this course's pending task.
     */
    public function test_get_taskid_for_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , , $options, $fingerprint, $runid] = $this->make_plan();
        $othercourse = $this->getDataGenerator()->create_course();

        $this->assertSame(0, apply_distribution::get_taskid_for_course((int) $course->id));

        $task = apply_distribution::create($options, $fingerprint, $runid);
        $taskid = \core\task\manager::queue_adhoc_task($task);

        $this->assertSame($taskid, apply_distribution::get_taskid_for_course((int) $course->id));
        $this->assertSame(0, apply_distribution::get_taskid_for_course((int) $othercourse->id));
        $this->assertInstanceOf(apply_distribution::class, apply_distribution::load($taskid));
    }

    /**
     * A task out of attempts no longer counts as the course's task, although
     * core keeps its row until the failed-task cleanup.
     *
     * The control is the same row with one attempt left. Changes that must
     * make it fail: dropping the attempts filter from get_taskid_for_course(),
     * which would refuse every new apply for the course until the cleanup.
     *
     * @return void
     */
    public function test_get_taskid_for_course_skips_a_task_out_of_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , , $options, $fingerprint, $runid] = $this->make_plan();
        $taskid = \core\task\manager::queue_adhoc_task(apply_distribution::create($options, $fingerprint, $runid));

        $DB->set_field('task_adhoc', 'attemptsavailable', 1, ['id' => $taskid]);
        $this->assertSame($taskid, apply_distribution::get_taskid_for_course((int) $course->id));

        $DB->set_field('task_adhoc', 'attemptsavailable', 0, ['id' => $taskid]);
        $this->assertTrue($DB->record_exists('task_adhoc', ['id' => $taskid]));
        $this->assertSame(0, apply_distribution::get_taskid_for_course((int) $course->id));
    }
}
