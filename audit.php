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
 * Distribution audit log (course report): run list and run detail.
 *
 * Read-only. Everything displayed comes from the stored snapshots; the only
 * reader-side overlays are field-visibility masking and the removed marker
 * for pseudonymised participants.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/group/lib.php');

$courseid = required_param('id', PARAM_INT);
$runid = optional_param('run', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$memberpage = optional_param('mpage', 0, PARAM_INT);
$userquery = optional_param('uq', '', PARAM_TEXT);
$groupquery = optional_param('gq', '', PARAM_TEXT);
// 0 addresses the real "without a group" section, so absent has to be -1.
$groupid = optional_param('group', \local_groupdist\output\audit_detail::GROUP_ANY, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = \core\context\course::instance($course->id);
require_capability('local/groupdist:viewauditlog', $context);

$urlparams = ['id' => $course->id];
if ($runid) {
    $urlparams['run'] = $runid;
    foreach (['uq' => $userquery, 'gq' => $groupquery] as $key => $value) {
        if (trim($value) !== '') {
            $urlparams[$key] = $value;
        }
    }
    if ($groupid !== \local_groupdist\output\audit_detail::GROUP_ANY) {
        $urlparams['group'] = $groupid;
    }
}
$PAGE->set_url(new moodle_url('/local/groupdist/audit.php', $urlparams));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('auditlog', 'local_groupdist'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(
    get_string('auditlog', 'local_groupdist'),
    new moodle_url('/local/groupdist/audit.php', ['id' => $course->id])
);

$detail = null;
if ($runid) {
    $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);
    if ((int) $run->courseid !== (int) $course->id) {
        // A run id belonging to another course must not leak across contexts.
        throw new moodle_exception('invaliddata', 'error');
    }
    $detail = new \local_groupdist\output\audit_detail($run, $context, [
        'userquery' => $userquery,
        'groupquery' => $groupquery,
        'page' => $page,
        'group' => $groupid,
        'memberpage' => $memberpage,
    ]);
    $PAGE->requires->js_call_amd('local_groupdist/audit', 'init');
}

echo $OUTPUT->header();

if ($detail) {
    echo $OUTPUT->render_from_template('local_groupdist/audit_detail', $detail->export_for_template($OUTPUT));
} else {
    echo $OUTPUT->heading(get_string('auditlog', 'local_groupdist'));
    $list = new \local_groupdist\output\audit_list((int) $course->id, $page);
    echo $OUTPUT->render_from_template('local_groupdist/audit_list', $list->export_for_template($OUTPUT));
    echo $OUTPUT->paging_bar(
        $list->get_total(),
        $page,
        \local_groupdist\output\audit_list::PER_PAGE,
        new moodle_url('/local/groupdist/audit.php', ['id' => $course->id])
    );
}

echo $OUTPUT->footer();
