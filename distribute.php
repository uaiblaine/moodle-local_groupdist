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
 * Distribution flow controller: options form (step 1) and preview (step 2).
 *
 * Reached by POST only — the injected button on group/index.php submits the
 * core form here (carrying groups[], id and sesskey), and every later
 * transition (preview, back) is a POST as well.
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
require_capability('local/groupdist:distribute', $context);
/* No page-level require_sesskey() here: the language menu re-requests this URL
   as a plain GET without a sesskey and must not explode. Nothing on this page
   mutates state — form submissions are sesskey-checked by formslib, and the
   mutating endpoint (apply.php) keeps its own require_sesskey(). */

\local_groupdist\local\fields::ensure_fields_exist();

// Entry POST from group/index.php carries groups[]; round trips carry a csv.
$groupids = optional_param_array('groups', [], PARAM_INT);
if (!$groupids) {
    $csv = optional_param('groupids', '', PARAM_SEQUENCE);
    $groupids = array_filter(array_map('intval', explode(',', $csv)));
}
$coursegroups = groups_get_all_groups($course->id);
$groupids = array_values(array_unique(array_intersect(
    array_map('intval', $groupids),
    array_map('intval', array_keys($coursegroups))
)));

$returnurl = new moodle_url('/group/index.php', ['id' => $course->id]);
if (!$groupids) {
    if (data_submitted()) {
        redirect($returnurl, get_string('errornogroups', 'local_groupdist'), null, \core\output\notification::NOTIFY_ERROR);
    }
    // Plain GET without a selection (e.g. a language switch): back to the
    // groups page quietly — the new language is already set for the session.
    redirect($returnurl);
}

$PAGE->set_url(new moodle_url('/local/groupdist/distribute.php', ['id' => $course->id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('distributeparticipants', 'local_groupdist'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('groups', 'group'), $returnurl);
$PAGE->navbar->add(get_string('distributeparticipants', 'local_groupdist'));

$rolenames = role_fix_names(get_profile_roles($context), $context, ROLENAME_BOTH, true);

$groupvalues = \local_groupdist\local\fields::get_group_values($groupids);
$noseats = 0;
foreach ($groupvalues as $value) {
    if ($value->seats === null) {
        $noseats++;
    }
}

$form = new \local_groupdist\form\options_form(null, [
    'context' => $context,
    'courseid' => $course->id,
    'groupids' => $groupids,
    'roles' => $rolenames,
    'noseats' => $noseats,
    'seatslabel' => \local_groupdist\local\fields::get_seats_label(),
    // Fresh entry: empty. Redisplays (validation error, back from preview)
    // repopulate the builder from the flattened POST arrays.
    'initialrules' => \local_groupdist\local\options::rules_from_post(),
]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

$data = $form->get_data();

if ($data && !empty($data->previewbutton)) {
    // Step 2: preview. Everything below the recap renders client-side from the
    // preview web service; apply/back live in a core sticky footer.
    $options = \local_groupdist\local\options::from_array([
        'courseid' => $course->id,
        'groupids' => $groupids,
        'roleid' => $data->roleid ?? 0,
        'cohortid' => $data->cohortid ?? 0,
        'allocateby' => $data->allocateby,
        'ignoregrouped' => !empty($data->ignoregrouped),
        'onlyactive' => !empty($data->includeonlyactiveenrol),
        'includefuture' => !empty($data->includefuture),
        'affinityrules' => \local_groupdist\local\options::rules_from_post(),
        'useseats' => !empty($data->useseats),
        'overbook' => $data->overbook ?? 0,
        'seed' => $data->seed,
    ]);

    $PAGE->requires->js_call_amd('local_groupdist/preview', 'init');

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('previewdistribution', 'local_groupdist'));
    echo $OUTPUT->render_from_template(
        'local_groupdist/preview',
        (new \local_groupdist\output\preview_page($options, $context, $rolenames))->export_for_template($OUTPUT)
    );

    $footercontext = [
        'applyurl' => (new moodle_url('/local/groupdist/apply.php'))->out(false),
        'backurl' => $PAGE->url->out(false),
        'cancelurl' => $returnurl->out(false),
        'sesskey' => sesskey(),
        'fields' => call_user_func(function () use ($options): array {
            // Flatten the canonical shape into scalar hidden inputs; the
            // ruleset becomes the two parallel arrays rules_from_post() reads.
            $fields = [];
            foreach ($options->to_array() as $name => $value) {
                if ($name === 'affinityrules') {
                    foreach ($value as $i => $rule) {
                        $fields[] = ['name' => "affinityrulesources[{$i}]", 'value' => $rule['source']];
                        $fields[] = ['name' => "affinityrulemodes[{$i}]", 'value' => $rule['mode']];
                    }
                    continue;
                }
                $fields[] = ['name' => $name, 'value' => $value];
            }
            return $fields;
        }),
    ];
    $stickycontent = $OUTPUT->render_from_template('local_groupdist/footer_actions', $footercontext);
    $stickyfooter = new \core\output\sticky_footer($stickycontent);
    echo $OUTPUT->render($stickyfooter);

    echo $OUTPUT->footer();
    exit;
}

// Step 1: options form (fresh entry, validation error, or back from preview).
if (!$form->is_submitted()) {
    $defaults = ['groupids' => implode(',', $groupids)];
    if (optional_param('back', 0, PARAM_BOOL)) {
        // Returning from the preview: repopulate every option the teacher chose.
        $posted = \local_groupdist\local\options::from_array([
            'courseid' => $course->id,
            'groupids' => $groupids,
            'roleid' => optional_param('roleid', 0, PARAM_INT),
            'cohortid' => optional_param('cohortid', 0, PARAM_INT),
            'allocateby' => optional_param('allocateby', 'random', PARAM_ALPHA),
            'ignoregrouped' => optional_param('ignoregrouped', 0, PARAM_BOOL),
            'onlyactive' => optional_param('onlyactive', 0, PARAM_BOOL),
            'includefuture' => optional_param('includefuture', 0, PARAM_BOOL),
            'affinityrules' => \local_groupdist\local\options::rules_from_post(),
            'useseats' => optional_param('useseats', 0, PARAM_BOOL),
            'overbook' => optional_param('overbook', 0, PARAM_INT),
            'seed' => optional_param('seed', 0, PARAM_INT),
        ]);
        $defaults += [
            'roleid' => $posted->roleid,
            'cohortid' => $posted->cohortid,
            'allocateby' => $posted->allocateby,
            'ignoregrouped' => (int) $posted->ignoregrouped,
            'includeonlyactiveenrol' => (int) $posted->onlyactive,
            'includefuture' => (int) $posted->includefuture,
            'useseats' => (int) $posted->useseats,
            'overbook' => $posted->overbook,
            'seed' => $posted->seed,
        ];
    }
    if (empty($defaults['seed'])) {
        // One fresh seed per flow; it round-trips through preview and apply so
        // the random order stays identical end to end.
        $defaults['seed'] = random_int(1, 2147483646);
    }
    $form->set_data($defaults);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('distributeparticipants', 'local_groupdist'));
echo $OUTPUT->render_from_template('local_groupdist/selected_groups', [
    'groups' => array_map(
        function (int $groupid) use ($coursegroups): array {
            return ['name' => format_string($coursegroups[$groupid]->name)];
        },
        array_slice($groupids, 0, 8)
    ),
    'morecount' => max(0, count($groupids) - 8),
    'total' => count($groupids),
]);
$form->display();
echo $OUTPUT->footer();
