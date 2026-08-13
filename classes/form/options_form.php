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
 * small group" checkbox is deliberately absent: it only applies when the group
 * COUNT is derived from a members-per-group number (core disables it in the
 * fixed-count mode, which is the only mode here), and this allocator always
 * balances sizes within one member.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class options_form extends \moodleform {
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

        // Section: affinity rules. The builder is an AMD-driven widget whose
        // rows post through the flattened affinityrulesources[]/modes[]
        // hidden inputs (read back via options::rules_from_post()).
        global $OUTPUT, $PAGE;
        $sources = [];
        foreach (profilefields::get_available($context) as $value => $label) {
            if ($value === '') {
                continue;
            }
            $sources[] = ['value' => $value, 'label' => $label];
        }
        $mform->addElement('header', 'affinityhdr', get_string('affinitysection', 'local_groupdist'));
        $mform->setExpanded('affinityhdr', true);
        $mform->addElement('html', $OUTPUT->render_from_template('local_groupdist/rules_builder', [
            'sourcesjson' => json_encode($sources),
            'rulesjson' => json_encode($this->_customdata['initialrules'] ?? []),
            'maxrules' => \local_groupdist\local\ruleset::DEFAULT_MAX_RULES,
        ]));
        $mform->addElement('static', 'affinityruleserr', '', '');
        $PAGE->requires->js_call_amd('local_groupdist/rules', 'init');

        // Section: seats and overbooking. Labels echo the field's STORED name
        // (set once at provisioning time): a site provisioned in English shows
        // "Seats" here even when the UI language is Portuguese.
        $seatslabel = (string) $this->_customdata['seatslabel'];
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

        /* The builder's rows are not registered elements, so they arrive via
           the flattened POST arrays instead of $data. Structural validation
           (shape, duplicates, guardrail) and per-source authorization both
           run here so a bad ruleset never reaches the preview. */
        $rules = options::rules_from_post();
        try {
            $ruleset = \local_groupdist\local\ruleset::from_array($rules);
            foreach ($ruleset->get_rules() as $rule) {
                if (!profilefields::is_allowed($rule['source'], $context)) {
                    $errors['affinityruleserr'] = get_string('invaliddata', 'error');
                    break;
                }
            }
        } catch (\moodle_exception $exception) {
            $errors['affinityruleserr'] = get_string('invaliddata', 'error');
        }
        return $errors;
    }
}
