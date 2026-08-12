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
 * Apply endpoint (step 3): recompute, re-verify the fingerprint, write.
 *
 * Small runs write inline; large runs queue an adhoc task with a stored
 * progress bar shown on status.php.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/group/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$fingerprint = required_param('fingerprint', PARAM_ALPHANUM);
$course = get_course($courseid);
require_login($course);
$context = \core\context\course::instance($course->id);
require_capability('local/groupdist:distribute', $context);
require_sesskey();

$returnurl = new moodle_url('/group/index.php', ['id' => $course->id]);
$PAGE->set_url(new moodle_url('/local/groupdist/apply.php', ['courseid' => $course->id]));

$options = \local_groupdist\local\options::from_array([
    'courseid' => $course->id,
    'groupids' => optional_param('groupids', '', PARAM_SEQUENCE),
    'roleid' => optional_param('roleid', 0, PARAM_INT),
    'cohortid' => optional_param('cohortid', 0, PARAM_INT),
    'allocateby' => optional_param('allocateby', 'random', PARAM_ALPHA),
    'ignoregrouped' => optional_param('ignoregrouped', 0, PARAM_BOOL),
    'onlyactive' => optional_param('onlyactive', 0, PARAM_BOOL),
    'affinityfield' => optional_param('affinityfield', '', PARAM_ALPHANUMEXT),
    'affinitymode' => optional_param('affinitymode', 'together', PARAM_ALPHA),
    'useseats' => optional_param('useseats', 0, PARAM_BOOL),
    'overbook' => optional_param('overbook', 0, PARAM_INT),
    'seed' => optional_param('seed', 0, PARAM_INT),
]);

// Server-side re-validation: never trust the round-tripped fields.
$coursegroups = groups_get_all_groups($course->id);
$options->groupids = array_values(array_intersect($options->groupids, array_map('intval', array_keys($coursegroups))));
if (!$options->groupids) {
    redirect($returnurl, get_string('errornogroups', 'local_groupdist'), null, \core\output\notification::NOTIFY_ERROR);
}
if (!\local_groupdist\local\profilefields::is_allowed($options->affinityfield, $context)) {
    throw new moodle_exception('invaliddata', 'error');
}
if ($options->cohortid) {
    require_once($CFG->dirroot . '/cohort/lib.php');
    if (!cohort_get_cohort($options->cohortid, $context)) {
        throw new moodle_exception('invaliddata', 'error');
    }
}

// One distribution task per course at a time.
if (\local_groupdist\task\apply_distribution::get_taskid_for_course($course->id)) {
    redirect(
        new moodle_url('/local/groupdist/status.php', ['id' => $course->id]),
        get_string('errortaskpending', 'local_groupdist'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$distribution = \local_groupdist\local\distribution::build($options, $context);
if ($distribution->fingerprint !== $fingerprint) {
    redirect($returnurl, get_string('errorstale', 'local_groupdist'), null, \core\output\notification::NOTIFY_ERROR);
}

$memberships = $distribution->allocation->count_memberships();
if ($memberships === 0) {
    redirect($returnurl, get_string('nothingtoapply', 'local_groupdist'), null, \core\output\notification::NOTIFY_INFO);
}

if ($memberships <= \local_groupdist\local\applier::INLINE_LIMIT) {
    $summary = \local_groupdist\local\applier::apply($distribution);
    redirect(
        $returnurl,
        get_string('appliedsummary', 'local_groupdist', (object) [
            'added' => $summary['added'],
            'groups' => count($options->groupids),
        ]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Large run: hand off to an adhoc task with stored progress.
$task = \local_groupdist\task\apply_distribution::create($options, $distribution->fingerprint);
$task->set_userid($USER->id);
$taskid = \core\task\manager::queue_adhoc_task($task, true);
if ($taskid) {
    $task->set_id($taskid);
    $task->initialise_stored_progress();
}
redirect(new moodle_url('/local/groupdist/status.php', ['id' => $course->id]));
