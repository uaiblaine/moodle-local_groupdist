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

namespace local_groupdist\form;

use local_groupdist\local\fields;
use local_groupdist\local\options;
use local_groupdist\local\profilefields;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Distribution options form (step 1 of the flow).
 *
 * Member-source and allocation options mirror core group/autogroup_form.php;
 * the affinity and seats sections are this plugin's own. Core's "prevent last
 * small group" checkbox is deliberately absent: core disables it unless the
 * group count is derived from a members-per-group number, and this plugin
 * always fills a fixed set of groups, balancing sizes within one member.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class options_form extends \moodleform {
    /** @var int Most cohorts shown as a plain menu; beyond it the picker becomes a search. */
    public const COHORT_MENU_LIMIT = 10;

    /* Higher than the cohort limit: cohorts are site-level and can number in
       the thousands, while a course with a dozen groups is ordinary. The value
       matches get_preview::GROUP_CAP, the most groups the preview shows; keep
       the two in step. */
    /** @var int Most course groups shown as a plain menu; beyond it the picker becomes a search. */
    public const GROUP_MENU_LIMIT = 25;

    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $context = $this->_customdata['context'];
        $courseid = (int) $this->_customdata['courseid'];
        $groupids = $this->_customdata['groupids'];
        $noseats = (int) $this->_customdata['noseats'];

        // Section: members (source filters), autogroup parity.
        $mform->addElement('header', 'membershdr', get_string('groupmembers', 'group'));
        $mform->setExpanded('membershdr', true);

        $roleoptions = [0 => get_string('all')] + $this->_customdata['roles'];
        $mform->addElement('select', 'roleid', get_string('selectfromrole', 'group'), $roleoptions);
        $student = get_archetype_roles('student');
        $student = reset($student);
        if ($student && array_key_exists($student->id, $roleoptions)) {
            $mform->setDefault('roleid', $student->id);
        }

        /* Unbounded on purpose, unlike the rule builder's cohort list below
           (COHORT_ALL, bounded only by the context chain, hence its search).
           COHORT_WITH_ENROLLED_MEMBERS_ONLY offers a cohort only when one of
           its members is enrolled in this course; core counts any enrolment,
           active or not, so a cohort may be offered and still yield nobody.
           This is argument-for-argument core's call in group/autogroup_form.php.
           A limit would not make it cheaper: cohort_get_available_cohorts()
           aggregates in a derived table and the LIMIT applies to the outer
           query, so every membership row is scanned either way. A search
           instead of a menu would pay the same aggregate on every keystroke,
           so this stays a menu.

           On the front page the bound does not hold: get_enrolled_join() skips
           the enrolment join at SITEID, so every system-context cohort with a
           non-deleted member is offered.

           Pinned by options_form_test::test_the_member_filter_is_bounded_by_the_roster. */
        if ($cohorts = cohort_get_available_cohorts($context, COHORT_WITH_ENROLLED_MEMBERS_ONLY, 0, 0)) {
            $cohortoptions = [0 => get_string('anycohort', 'cohort')];
            foreach ($cohorts as $cohort) {
                $cohortoptions[$cohort->id] = format_string($cohort->name, true, [
                    'context' => \core\context::instance_by_id($cohort->contextid),
                ]);
            }
            $mform->addElement('select', 'cohortid', get_string('selectfromcohort', 'cohort'), $cohortoptions);
            $mform->setDefault('cohortid', 0);
        } else {
            $mform->addElement('hidden', 'cohortid');
            $mform->setType('cohortid', PARAM_INT);
            $mform->setConstant('cohortid', 0);
        }

        $mform->addElement('checkbox', 'ignoregrouped', get_string('ignoregrouped', 'local_groupdist'));
        $mform->addHelpButton('ignoregrouped', 'ignoregrouped', 'local_groupdist');
        $mform->setDefault('ignoregrouped', 1);

        if (has_capability('moodle/course:viewsuspendedusers', $context)) {
            $mform->addElement('checkbox', 'includeonlyactiveenrol', get_string('includeonlyactiveenrol', 'group'), '');
            $mform->addHelpButton('includeonlyactiveenrol', 'includeonlyactiveenrol', 'group');
            $mform->setDefault('includeonlyactiveenrol', 1);

            $mform->addElement('checkbox', 'includefuture', get_string('includefutureenrol', 'local_groupdist'), '');
            $mform->addHelpButton('includefuture', 'includefutureenrol', 'local_groupdist');
            $mform->setDefault('includefuture', 0);
            // Without the only-active filter, future enrolments are already in.
            $mform->disabledIf('includefuture', 'includeonlyactiveenrol', 'notchecked');
        }

        // Section: allocation order.
        $mform->addElement('header', 'allochdr', get_string('allocationsection', 'local_groupdist'));
        $mform->setExpanded('allochdr', true);
        $allocateoptions = [
            options::ALLOCATE_RANDOM => get_string('random', 'group'),
            options::ALLOCATE_FIRSTNAME => get_string('byfirstname', 'group'),
            options::ALLOCATE_LASTNAME => get_string('bylastname', 'group'),
            options::ALLOCATE_IDNUMBER => get_string('byidnumber', 'group'),
        ];
        $mform->addElement('select', 'allocateby', get_string('allocateby', 'group'), $allocateoptions);
        $mform->setDefault('allocateby', options::ALLOCATE_RANDOM);

        // Section: affinity rules. The local_groupdist/rules AMD widget posts its
        // rows as flattened affinityrulesources[]/affinityrulemodes[] inputs,
        // read back by options::rules_from_post(). Cohorts and course groups are
        // a menu up to COHORT_MENU_LIMIT / GROUP_MENU_LIMIT and a search beyond.
        global $CFG, $OUTPUT, $PAGE;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $fields = [];
        foreach (profilefields::get_fields($context) as $value => $label) {
            $fields[] = ['value' => $value, 'label' => $label];
        }
        $cohorts = [];
        $cohortsearch = false;
        $sample = cohort_get_available_cohorts($context, COHORT_ALL, 0, self::COHORT_MENU_LIMIT + 1);
        if (count($sample) > self::COHORT_MENU_LIMIT) {
            $cohortsearch = true;
        } else {
            foreach ($sample as $cohort) {
                $cohorts[] = [
                    'value' => 'cohort_' . (int) $cohort->id,
                    /* Plain: the rule builder's row template escapes it (double
                       stash), unlike the cohortid select above, which core
                       renders through a triple stash and so stays escaped. */
                    'label' => format_string($cohort->name, true, [
                        'context' => \core\context::instance_by_id($cohort->contextid),
                        'escape' => false,
                    ]),
                ];
            }
        }
        /* Served by the helper profilefields::is_allowed() validates against,
           so the picker never offers what validation() rejects. The destination
           ids travel too: the builder disables a destination group as a source
           while the ignore-grouped filter is on (see validation()). */
        $sourcegroups = profilefields::get_source_groups($context);
        $groups = [];
        $groupsearch = count($sourcegroups) > self::GROUP_MENU_LIMIT;
        if (!$groupsearch) {
            foreach ($sourcegroups as $id => $name) {
                // Plain, like the cohort labels beside them: the row template
                // prints an <option> through a double stash.
                $groups[] = ['value' => 'group_' . $id, 'label' => $name];
            }
        }

        $initialrules = [];
        foreach (($this->_customdata['initialrules'] ?? []) as $rule) {
            $initialrules[] = $rule + [
                'label' => profilefields::get_label($rule['source'], $context),
            ];
        }

        $mform->addElement('header', 'affinityhdr', get_string('affinitysection', 'local_groupdist'));
        $mform->setExpanded('affinityhdr', true);
        $mform->addElement('html', $OUTPUT->render_from_template('local_groupdist/rules_builder', [
            'fieldsjson' => json_encode($fields),
            'cohortsjson' => json_encode($cohorts),
            'cohortsearch' => $cohortsearch,
            'groupsjson' => json_encode($groups),
            'groupsearch' => $groupsearch,
            'destinationsjson' => json_encode(array_values(array_map('intval', (array) $groupids))),
            'courseid' => $courseid,
            'rulesjson' => json_encode($initialrules),
            'maxrules' => \local_groupdist\local\ruleset::DEFAULT_MAX_RULES,
        ]));
        $mform->addElement('static', 'affinityruleserr', '', '');
        $PAGE->requires->js_call_amd('local_groupdist/rules', 'init');

        // Section: seats and overbooking. Labels echo the field's stored name,
        // set once at provisioning, so they do not follow the UI language.
        /* Escaped, not plain: both sinks below are triple stashes (core's
           element label and static element templates). Every other consumer
           of this label escapes for itself and takes the plain spelling. */
        $seatslabel = fields::get_seats_label(true);
        $mform->addElement('header', 'seatshdr', get_string('seatssection', 'local_groupdist'));
        $mform->setExpanded('seatshdr', true);
        $mform->addElement('advcheckbox', 'useseats', get_string('useseats', 'local_groupdist', $seatslabel));
        $mform->addHelpButton('useseats', 'useseats', 'local_groupdist', '', false, $seatslabel);
        $mform->setDefault('useseats', 1);

        $mform->addElement('text', 'overbook', get_string('overbook', 'local_groupdist'), 'maxlength="2" size="4"');
        $mform->setType('overbook', PARAM_INT);
        $mform->setDefault('overbook', 0);
        $mform->addHelpButton('overbook', 'overbook', 'local_groupdist');
        $mform->disabledIf('overbook', 'useseats', 'notchecked');

        if ($noseats > 0) {
            $a = (object) ['noseats' => $noseats, 'total' => count($groupids), 'field' => $seatslabel];
            $mform->addElement('static', 'noseatsnote', '', get_string('noseatsnote', 'local_groupdist', $a));
        }

        // Round-tripped state.
        $mform->addElement('hidden', 'id', $courseid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'groupids');
        $mform->setType('groupids', PARAM_SEQUENCE);
        $mform->addElement('hidden', 'seed');
        $mform->setType('seed', PARAM_INT);

        $buttons = [];
        $buttons[] = $mform->createElement('submit', 'previewbutton', get_string('previewdistribution', 'local_groupdist'));
        $buttons[] = $mform->createElement('cancel');
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Server-side validation.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $context = $this->_customdata['context'];

        if (($data['overbook'] ?? 0) < 0 || ($data['overbook'] ?? 0) > 99) {
            $errors['overbook'] = get_string('erroroverbookrange', 'local_groupdist');
        }

        /* Defence in depth. HTML_QuickForm_select::exportValue() already drops
           a value matching no option, so a forged cohortid is absent from $data
           (hence distribute.php's "?? 0"); that guard disappears if the element
           becomes an ajax autocomplete, whose exportValue() returns the value
           unchecked. The check matches the other entry points (apply.php,
           get_preview, preview_page): a hidden cohort is never a membership
           oracle. */
        if (!empty($data['cohortid']) && !cohort_get_cohort((int) $data['cohortid'], $context)) {
            $errors['cohortid'] = get_string('invaliddata', 'error');
        }

        /* The builder's rows are not registered elements, so they arrive via
           the flattened POST arrays instead of $data. Structural validation
           (shape, duplicates, guardrail) and per-source authorization both
           run here so a bad ruleset never reaches the preview. */
        $rules = options::rules_from_post();
        try {
            $ruleset = \local_groupdist\local\ruleset::from_array($rules);
            $destinations = array_map('intval', (array) ($this->_customdata['groupids'] ?? []));
            $ignoregrouped = !empty($data['ignoregrouped']);
            foreach ($ruleset->get_rules() as $i => $rule) {
                if (!profilefields::is_allowed($rule['source'], $context)) {
                    $errors['affinityruleserr'] = get_string('invaliddata', 'error');
                    break;
                }
                /* A destination group as a rule source is vacuous exactly when
                   the ignore filter is on: candidates::fetch() then excludes
                   every user already in the selected groups, so no survivor
                   holds the value. With the filter off the rule constrains real
                   members, so this is a conjunction, not a ban on the source.
                   The builder disables these options while the filter is on;
                   this catches a forged POST or the filter ticked after the
                   rule was picked, and names the rule and the reason. */
                $groupid = \local_groupdist\local\ruleset::source_groupid($rule['source']);
                if ($ignoregrouped && $groupid && in_array($groupid, $destinations, true)) {
                    /* Escaped, unlike the picker list in definition(): core
                       renders an element's error through a triple stash
                       (lib/form/templates/element-template.mustache). */
                    $errors['affinityruleserr'] = get_string('errorruleselfreference', 'local_groupdist', (object) [
                        'index' => $i + 1,
                        'group' => profilefields::get_source_groups($context, true)[$groupid] ?? '',
                    ]);
                    break;
                }
            }
        } catch (\moodle_exception $exception) {
            $errors['affinityruleserr'] = get_string('invaliddata', 'error');
        }
        return $errors;
    }
}
