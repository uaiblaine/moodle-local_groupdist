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
    /** @var int Most cohorts shown as a plain menu; beyond it the picker becomes a search. */
    public const COHORT_MENU_LIMIT = 10;

    /* Groups get their own, higher limit rather than sharing the cohort one.
       The cohort bound exists because cohorts are site-level and platforms
       carry thousands; groups are course-bounded and a course with a dozen
       groups is ordinary, so flipping such a course to a search box would be
       worse UI, not safer. 25 is deliberately the number the plugin already
       treats as "too many groups to show at once" — get_preview::GROUP_CAP —
       so there is one such constant in the plugin, not two. */
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

        /* Unbounded ON PURPOSE, and not the same posture as the rule builder
           below — the two calls select different sets, and that difference is
           the whole justification.

           1. This is COHORT_WITH_ENROLLED_MEMBERS_ONLY, whose
              "HAVING COUNT(DISTINCT u.id) > 0" (cohort/lib.php) admits a
              cohort only when one of its members appears in this course's
              enrolment list. Note that core builds that list with
              get_enrolled_sql($context) and no only-active flag, so it counts
              suspended users, disabled enrol instances and expired or future
              enrolments — a looser roster than this plugin's own candidate
              query, which defaults to active enrolments only. The bound is
              therefore real but wider than the eventual candidate set: a
              cohort whose sole overlap is a suspended enrolment is offered
              here and yields nothing.
              The rule builder's call below is COHORT_ALL, which is bounded
              only by the course's context chain — hence the never-enumerate
              rule there, and hence its search fallback beyond a small menu.
           2. The call is argument-for-argument core's own in
              group/autogroup_form.php, reached from the same group management
              page this plugin's button sits on. A site pays here exactly what
              core already charges it on the Auto-create groups form beside it.
           3. A limit would not reduce that cost, which is the measured reason
              for keeping the call as it stands rather than capping it.
              cohort/lib.php puts the grouping and the HAVING in a derived
              table, joins it back on cohort.id and orders the OUTER query, so
              the LIMIT get_records_sql() appends to the finished outer string
              cannot reach the aggregate — every membership row of every
              visible cohort is scanned either way, and a run with LIMIT 11
              showed no measurable saving over an unbounded one. Capping would
              buy no query time, hide valid choices, and falsify the autogroup
              parity this section claims; and the honest alternative, a
              members-filtered search, would pay that same aggregate on every
              keystroke instead of once per render. The aggregate is core's to
              fix upstream, where autogroup_form gets the fix too.

           Where the bound does NOT hold: the front page. get_enrolled_join()
           skips the {user_enrolments} join entirely at SITEID (everyone counts
           as enrolled there), so the HAVING degenerates to "the cohort has any
           member whose account is not deleted". The site course's context
           chain is itself plus system, so what that offers is every
           system-context cohort — honest rather than misleading, since on the
           front page each of them genuinely can yield participants, but
           unbounded by anything course-shaped. It is the case to revisit first
           if this decision is reopened.

           Pinned by options_form_test::test_the_member_filter_is_bounded_by_the_roster,
           which goes red if this mode is widened. */
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
        // hidden inputs (read back via options::rules_from_post()). Each row
        // picks a type first (profile field, cohort or course group); cohorts
        // are a bounded list up to COHORT_MENU_LIMIT and a search beyond it —
        // platforms can carry thousands, so they are never enumerated — and
        // course groups follow the same two-mode shape at GROUP_MENU_LIMIT,
        // for usability rather than disclosure.
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
                    /* Escaped by the rule builder's own template, which prints
                       an <option> through a DOUBLE stash — unlike the cohortid
                       select above, which core renders through a triple stash
                       and whose label must therefore stay escaped. */
                    'label' => format_string($cohort->name, true, [
                        'context' => \core\context::instance_by_id($cohort->contextid),
                        'escape' => false,
                    ]),
                ];
            }
        }
        /* The group picker is served from the same helper the submit-side
           validator uses, so the picker can never offer what validation()
           rejects. The destination ids travel too: a destination group used as
           a rule source is vacuous while "ignore users already in the selected
           groups" is on (every candidate carrying the value has been filtered
           out of the run by construction), so the builder disables those
           options live and re-enables them when that checkbox is unticked. */
        $sourcegroups = profilefields::get_source_groups($context);
        $groups = [];
        $groupsearch = count($sourcegroups) > self::GROUP_MENU_LIMIT;
        if (!$groupsearch) {
            foreach ($sourcegroups as $id => $name) {
                // Plain, like the cohort labels beside them: the row template
                // prints an <option> through a DOUBLE stash.
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

        // Section: seats and overbooking. Labels echo the field's STORED name
        // (set once at provisioning time): a site provisioned in English shows
        // "Seats" here even when the UI language is Portuguese.
        /* Escaped, not plain: both sinks below are triple stashes — core
           renders an element label through {{{label}}} and a static element
           through {{{element.html}}}. Every other consumer of this label
           escapes for itself and takes the plain spelling. */
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

        /* Defence in depth, and deliberately unreachable today. The member
           filter is a plain select, and HTML_QuickForm_select::exportValue()
           silently drops a submitted value matching no registered option — so
           a forged cohortid never reaches this method at all; the key is
           absent from $data, which is why distribute.php needs its "?? 0".
           That makes the option list, built from
           cohort_get_available_cohorts(), an authorization allowlist by
           accident of PEAR rather than by any decision this plugin wrote
           down, and the accident evaporates the moment the element type
           changes: an ajax autocomplete's exportValue() returns the submitted
           value unchecked. Stating the check makes this form agree with the
           other three entry points (apply.php, get_preview, preview_page),
           all of which gate a raw cohortid on cohort_get_cohort() so a hidden
           cohort can never be used as a membership oracle. */
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
                /* A destination group as a rule source is vacuous EXACTLY when
                   the ignore filter is on: candidates::fetch() then excludes
                   every user already holding a membership row in the selected
                   groups, so the value column is empty for 100% of survivors
                   and the only trace is one "no value" warning naming the whole
                   candidate count. With the filter off those members do take
                   part and the rule genuinely constrains them, so this is a
                   conjunction, never a blanket ban on the source.

                   The builder disables these options while the filter is on, so
                   reaching this needs a forged POST or the filter being unticked
                   after the rule was picked — but the message still has to say
                   which rule and why, because the generic invaliddata above
                   cannot. */
                $groupid = \local_groupdist\local\ruleset::source_groupid($rule['source']);
                if ($ignoregrouped && $groupid && in_array($groupid, $destinations, true)) {
                    /* ESCAPED, unlike the picker list a few lines above: core
                       renders a moodleform element's error through a TRIPLE
                       stash (lib/form/templates/element-template.mustache), so
                       a group named "A & B" would otherwise reach the page raw.
                       Same split this form already holds for the cohortid
                       select against the rule builder's data attribute. */
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
