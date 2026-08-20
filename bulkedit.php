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
 * Bulk edit of group custom fields for the selected groups.
 *
 * Reached by POST from the injected button on group/index.php (groups[], id,
 * sesskey). Rendering mutates nothing: saves go through the chunked
 * local_groupdist_save_group_fields web service and the settings modal.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/group/lib.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = \core\context\course::instance($course->id);
require_capability('moodle/course:managegroups', $context);
/* No page-level require_sesskey(): the language menu re-requests this URL as
   a plain GET. All writes happen in the web service / modal, both guarded. */

\local_groupdist\local\fields::ensure_fields_exist();

$groupids = optional_param_array('groups', [], PARAM_INT);
if (!$groupids) {
    $csv = optional_param('groupids', '', PARAM_SEQUENCE);
    $groupids = array_filter(array_map('intval', explode(',', $csv)));
}
$coursegroups = groups_get_all_groups($course->id);
$selected = [];
foreach (array_unique(array_map('intval', $groupids)) as $groupid) {
    if (isset($coursegroups[$groupid])) {
        $selected[] = $coursegroups[$groupid];
    }
}

$returnurl = new moodle_url('/group/index.php', ['id' => $course->id]);
if (!$selected) {
    if (data_submitted()) {
        redirect($returnurl, get_string('errornogroupsedit', 'local_groupdist'), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($returnurl);
}

$PAGE->set_url(new moodle_url('/local/groupdist/bulkedit.php', ['id' => $course->id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('bulkeditgroups', 'local_groupdist'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('groups', 'group'), $returnurl);
$PAGE->navbar->add(get_string('bulkeditgroups', 'local_groupdist'));

$PAGE->requires->js_call_amd('local_groupdist/bulkedit', 'init');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bulkeditgroups', 'local_groupdist'));
echo $OUTPUT->render_from_template(
    'local_groupdist/bulkedit',
    (new \local_groupdist\output\bulkedit_page($course, $selected))->export_for_template($OUTPUT)
);

$stickycontent = $OUTPUT->render_from_template('local_groupdist/bulkedit_footer', [
    'returnurl' => $returnurl->out(false),
]);
echo $OUTPUT->render(new \core\output\sticky_footer($stickycontent));

echo $OUTPUT->footer();
