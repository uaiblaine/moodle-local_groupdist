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

use local_groupdist\local\distribution;
use local_groupdist\local\options;
use local_groupdist\local\runlog;
use local_groupdist\task\apply_distribution;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The background apply status page (status.php) rendered as a request would.
 *
 * A page script has no class to cover.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class status_page_test extends \advanced_testcase {
    /**
     * With no task left, an aborted run is reported as a warning carrying the
     * memberships it wrote, and a completed run as a success.
     *
     * Under PHPUnit the page renders through the CLI renderer, which prints a
     * success notification as "++ message ++" and any other type as
     * "!! message !!", so the markers stand for the notification type.
     * Changes that must make it fail: reporting every finished run with the
     * success notification again.
     *
     * @return void
     */
    public function test_an_aborted_run_is_not_reported_as_a_success(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_and_enrol($course);
        $distribution = distribution::build(options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'seed' => 31,
        ]), $context);

        $aborted = runlog::create($distribution, (int) get_admin()->id, $context);
        runlog::abort($aborted);
        $html = $this->render_status((int) $course->id);
        $this->assertStringContainsString('!! ' . get_string('applyaborted', 'local_groupdist', 0) . ' !!', $html);
        $this->assertStringNotContainsString(get_string('applyfinished', 'local_groupdist'), $html);
        $this->assertStringContainsString('name="run" value="' . $aborted . '"', $html);

        // Control: once a later run completes, the page reports a success.
        $completed = runlog::create($distribution, (int) get_admin()->id, $context);
        runlog::complete($completed, ['added' => 1, 'failed' => 0]);
        $html = $this->render_status((int) $course->id);
        $this->assertStringContainsString('++ ' . get_string('applyfinished', 'local_groupdist') . ' ++', $html);
        $this->assertStringNotContainsString(get_string('applyaborted', 'local_groupdist', 0), $html);
        $this->assertStringContainsString('name="run" value="' . $completed . '"', $html);
    }

    /**
     * A run still pending once no attempt of its task is left is reported as
     * unfinished, never as a success.
     *
     * A task out of attempts keeps its row until core's failed-task cleanup
     * deletes it, so both states are rendered. The control is the same task
     * with attempts left, which shows its progress instead. Changes that must
     * make it fail: dropping the pending arm from status.php, or counting a
     * task without attempts as the course's task
     * (apply_distribution::get_taskid_for_course()).
     *
     * @return void
     */
    public function test_a_pending_run_without_a_task_left_is_not_reported_as_a_success(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_and_enrol($course);
        $distribution = distribution::build(options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'seed' => 32,
        ]), $context);
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        $task = apply_distribution::create($distribution->options, $distribution->fingerprint, $runid);
        $task->set_userid((int) get_admin()->id);
        $taskid = \core\task\manager::queue_adhoc_task($task, true);
        $running = get_string('applyrunning', 'local_groupdist');
        $unfinished = '!! ' . get_string('applyunfinished', 'local_groupdist') . ' !!';

        // Control: with attempts left the task will run again, so its progress is shown.
        $html = $this->render_status((int) $course->id);
        $this->assertStringContainsString($running, $html);
        $this->assertStringNotContainsString(get_string('applyunfinished', 'local_groupdist'), $html);

        // Out of attempts: core keeps the row but never runs the task again.
        $DB->set_field('task_adhoc', 'attemptsavailable', 0, ['id' => $taskid]);
        $html = $this->render_status((int) $course->id);
        $this->assertStringContainsString($unfinished, $html);
        $this->assertStringNotContainsString($running, $html);
        $this->assertStringNotContainsString(get_string('applyfinished', 'local_groupdist'), $html);
        $this->assertStringContainsString('name="run" value="' . $runid . '"', $html);

        // Deleted by the cleanup: still unfinished.
        $DB->delete_records('task_adhoc', ['id' => $taskid]);
        $html = $this->render_status((int) $course->id);
        $this->assertStringContainsString($unfinished, $html);
        $this->assertStringNotContainsString(get_string('applyfinished', 'local_groupdist'), $html);
    }

    /**
     * A queued task is reported as running, its progress bar appears once the
     * started task has created it, and where core has no task indicator the
     * page reloads itself only while the task exists.
     *
     * The bar is matched by its element id, which the progress bar template
     * takes from the bar's idnumber. Changes that must make it fail: dropping
     * the bar or the periodic refresh from the indicator-less arm of
     * status.php, or keeping the refresh once the task is gone.
     *
     * @return void
     */
    public function test_a_started_task_shows_its_progress_bar(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_and_enrol($course);
        $distribution = distribution::build(options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'seed' => 33,
        ]), $context);
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        $task = apply_distribution::create($distribution->options, $distribution->fingerprint, $runid);
        $task->set_userid((int) get_admin()->id);
        $taskid = \core\task\manager::queue_adhoc_task($task, true);
        $idnumber = \core\output\stored_progress_bar::convert_to_idnumber(apply_distribution::class, $taskid);
        $refresh = class_exists(\core\output\task_indicator::class)
            ? null
            : (int) \core\output\stored_progress_bar::get_timeout();

        // Queued and not started: the message, and no bar to poll yet.
        $html = $this->render_status((int) $course->id);
        $this->assertStringContainsString(get_string('applyrunning', 'local_groupdist'), $html);
        $this->assertStringNotContainsString('id="' . $idnumber . '"', $html);
        $this->assertSame($refresh, $PAGE->periodicrefreshdelay);

        // Started: the task's bar has a row, so the page shows it.
        ob_start();
        (new \core\output\stored_progress_bar($idnumber))->start();
        ob_end_clean();
        $html = $this->render_status((int) $course->id);
        $this->assertStringContainsString(get_string('applyrunning', 'local_groupdist'), $html);
        $this->assertStringContainsString('id="' . $idnumber . '"', $html);
        $this->assertSame($refresh, $PAGE->periodicrefreshdelay);

        // Finished: the outcome stays on screen without reloading.
        $DB->delete_records('task_adhoc', ['id' => $taskid]);
        $html = $this->render_status((int) $course->id);
        $this->assertStringNotContainsString(get_string('applyrunning', 'local_groupdist'), $html);
        $this->assertNull($PAGE->periodicrefreshdelay);
    }

    /**
     * Render status.php for a course as the current user.
     *
     * @param int $courseid The course id parameter.
     * @return string The page HTML.
     */
    private function render_status(int $courseid): string {
        // The page runs in this method's scope and reads these as its globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SESSION, $SITE, $USER;

        $PAGE = new \moodle_page();
        $OUTPUT = new \bootstrap_renderer();
        $_GET = ['id' => $courseid];
        ob_start();
        try {
            require($CFG->dirroot . '/local/groupdist/status.php');
        } finally {
            $html = ob_get_clean();
            $_GET = [];
        }
        return $html;
    }
}
