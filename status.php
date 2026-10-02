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

/**
 * Background apply status page: core task indicator with a stored progress bar.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = \core\context\course::instance($course->id);
require_capability('local/groupdist:distribute', $context);

$returnurl = new moodle_url('/group/index.php', ['id' => $course->id]);
$PAGE->set_url(new moodle_url('/local/groupdist/status.php', ['id' => $course->id]));
$PAGE->set_pagelayout('standard');
\local_groupdist\local\bootstrap::mark_page();
$PAGE->set_title(get_string('distributeparticipants', 'local_groupdist'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('groups', 'group'), $returnurl);
$PAGE->navbar->add(get_string('distributeparticipants', 'local_groupdist'));

$taskid = \local_groupdist\task\apply_distribution::get_taskid_for_course($course->id);
$task = $taskid ? \local_groupdist\task\apply_distribution::load($taskid) : null;

/* core\output\task_indicator follows the task and leaves the page when it
   finishes. Moodle 4.5 has no indicator, so there the page reloads itself
   while the task exists, at the interval a stored progress bar polls at, and
   shows the outcome below once the task is gone. */
$hasindicator = class_exists(\core\output\task_indicator::class);
if ($task && !$hasindicator) {
    $PAGE->set_periodic_refresh_delay((int) \core\output\stored_progress_bar::get_timeout());
}

echo $OUTPUT->header();
if ($task && $hasindicator) {
    $indicator = new \core\output\task_indicator(
        $task,
        get_string('distributeparticipants', 'local_groupdist'),
        get_string('applyrunning', 'local_groupdist'),
        $returnurl
    );
    echo $OUTPUT->render($indicator);
} else if ($task) {
    // The indicator's heading and message, and the bar once the started task has created it.
    echo $OUTPUT->heading(get_string('distributeparticipants', 'local_groupdist'));
    echo $OUTPUT->notification(get_string('applyrunning', 'local_groupdist'), \core\output\notification::NOTIFY_INFO, false);
    $bar = \core\output\stored_progress_bar::get_by_idnumber(
        \core\output\stored_progress_bar::convert_to_idnumber(\local_groupdist\task\apply_distribution::class, $taskid)
    );
    if ($bar) {
        echo $bar->get_content();
    }
} else {
    /* No task left to run: the run finished, was aborted as stale, stopped
       unfinished (its task ran out of attempts or was deleted), or none was
       queued. The course's latest run tells these apart; the task's message
       to its owner reports the first two. */
    $runs = $DB->get_records('local_groupdist_run', ['courseid' => $course->id], 'id DESC', 'id, status, memberswritten', 0, 1);
    $run = reset($runs);
    $status = $run ? (int) $run->status : null;
    if ($status === \local_groupdist\local\runlog::STATUS_ABORTED) {
        echo $OUTPUT->notification(
            get_string('applyaborted', 'local_groupdist', (int) $run->memberswritten),
            \core\output\notification::NOTIFY_WARNING
        );
    } else if ($status === \local_groupdist\local\runlog::STATUS_PENDING) {
        echo $OUTPUT->notification(get_string('applyunfinished', 'local_groupdist'), \core\output\notification::NOTIFY_WARNING);
    } else {
        echo $OUTPUT->notification(get_string('applyfinished', 'local_groupdist'), \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($run && has_capability('local/groupdist:viewauditlog', $context)) {
        echo $OUTPUT->single_button(
            new moodle_url('/local/groupdist/audit.php', ['id' => $course->id, 'run' => $run->id]),
            get_string('auditlog', 'local_groupdist'),
            'get'
        );
    }
    echo $OUTPUT->continue_button($returnurl);
}
echo $OUTPUT->footer();
