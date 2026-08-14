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

/**
 * Adhoc apply task tests, most importantly the fingerprint staleness guard.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\task\apply_distribution
 */
final class apply_distribution_test extends \advanced_testcase {
    /**
     * Prepare a course with one group and users, returning options + fingerprint.
     *
     * @return array [course, context, group, options, fingerprint].
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
     * A matching fingerprint writes the memberships.
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
        $task->initialise_stored_progress();

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/applied distribution/');
        $task->execute();

        $this->assertSame(2, $DB->count_records('groups_members', ['groupid' => $group->id]));
        // The owner is told the background run finished.
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertSame('applyresult', $messages[0]->eventtype);
    }

    /**
     * The staleness guard: a fingerprint mismatch writes NOTHING — proven with
     * a control run showing the same plan does write when the world is unchanged.
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
        $task->initialise_stored_progress();

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/fingerprint mismatch/');
        $task->execute();

        $this->assertSame(0, $DB->count_records('groups_members', ['groupid' => $group->id]));
        // The abort is not silent: the owner receives a notification.
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
    }

    /**
     * An interrupted run resumes: its own partial writes (stamped with the
     * seed) are invisible to the recompute, so the fingerprint still matches
     * and the remainder is applied idempotently.
     */
    public function test_execute_resumes_after_partial_write(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $context, $group, $options, $fingerprint, $runid] = $this->make_plan();

        // Simulate the first attempt dying after one membership.
        $plan = distribution::build($options, $context);
        $firstuser = $plan->allocation->assignments[(int) $group->id][0];
        groups_add_member((int) $group->id, $firstuser, 'local_groupdist', $options->seed);

        $task = apply_distribution::create($options, $fingerprint, $runid);
        $task->set_userid(get_admin()->id);
        $taskid = \core\task\manager::queue_adhoc_task($task);
        $task->set_id($taskid);
        $task->initialise_stored_progress();

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/applied distribution/');
        $task->execute();
        $sink->close();

        $this->assertSame(2, $DB->count_records('groups_members', ['groupid' => $group->id]));
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
}
