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

require(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = \core\context\course::instance($course->id);
require_capability('local/groupdist:distribute', $context);

$returnurl = new moodle_url('/group/index.php', ['id' => $course->id]);
$PAGE->set_url(new moodle_url('/local/groupdist/status.php', ['id' => $course->id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('distributeparticipants', 'local_groupdist'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('groups', 'group'), $returnurl);
$PAGE->navbar->add(get_string('distributeparticipants', 'local_groupdist'));

$taskid = \local_groupdist\task\apply_distribution::get_taskid_for_course($course->id);

echo $OUTPUT->header();
if ($taskid && ($task = \local_groupdist\task\apply_distribution::load($taskid))) {
    $indicator = new \core\output\task_indicator(
        $task,
        get_string('distributeparticipants', 'local_groupdist'),
        get_string('applyrunning', 'local_groupdist'),
        $returnurl
    );
    echo $OUTPUT->render($indicator);
} else {
    // No pending task: the run finished (or none was queued).
    echo $OUTPUT->notification(get_string('applyfinished', 'local_groupdist'), \core\output\notification::NOTIFY_SUCCESS);
    echo $OUTPUT->continue_button($returnurl);
}
echo $OUTPUT->footer();
