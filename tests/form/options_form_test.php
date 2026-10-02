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

/**
 * Distribution options form: label escaping per sink (the cohort and group
 * lists escape differently on purpose), the member filter's cohort bound and
 * the rule source validation.
 *
 * PHPUnit metadata is written as docblock tags, never attributes: Moodle 4.5
 * runs PHPUnit 9, which reads only the tags, and its moodle-cs reports every
 * test of a class without a covers tag.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\form\options_form
 */
final class options_form_test extends \advanced_testcase {
    /** @var \core\context\course|null Course context of the last rendered form. */
    private ?\core\context\course $context = null;

    /** @var array Ids of the cohorts seeded with no member enrolled in the course. */
    private array $offroster = [];

    /** @var int Id of the group the last rendered form distributes into. */
    private int $destination = 0;

    /**
     * A course, a cohort whose name contains an ampersand, and the rendered
     * options form.
     *
     * Extra cohorts all share the single enrolled user: the member filter
     * admits a cohort when at least one of its members is enrolled in the
     * course, so that one user makes all of them eligible. Off-roster cohorts
     * share a user who is not enrolled.
     *
     * @param int $extracohorts Additional eligible cohorts to seed.
     * @param int $offroster Additional cohorts with NO enrolled member.
     * @param int $noseats How many selected groups the form is told lack a seats value.
     * @return string The rendered form HTML.
     */
    private function render_with_cohort(int $extracohorts = 0, int $offroster = 0, int $noseats = 0): string {
        global $CFG, $DB, $PAGE;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $context = \core\context\course::instance($course->id);
        $cohort = $this->getDataGenerator()->create_cohort([
            'contextid' => \core\context\system::instance()->id,
            'name' => 'Ciencias & Letras',
        ]);
        $user = $this->getDataGenerator()->create_and_enrol($course);
        cohort_add_member($cohort->id, $user->id);
        for ($i = 1; $i <= $extracohorts; $i++) {
            $filler = $this->getDataGenerator()->create_cohort([
                'contextid' => \core\context\system::instance()->id,
                'name' => sprintf('Filler %02d', $i),
            ]);
            cohort_add_member($filler->id, $user->id);
        }
        $stranger = $offroster ? $this->getDataGenerator()->create_user() : null;
        for ($i = 1; $i <= $offroster; $i++) {
            // Members, but nobody enrolled in this course.
            $off = $this->getDataGenerator()->create_cohort([
                'contextid' => \core\context\system::instance()->id,
                'name' => sprintf('Offroster %02d', $i),
            ]);
            cohort_add_member($off->id, $stranger->id);
            $this->offroster[] = (int) $off->id;
        }
        $this->context = $context;
        $group = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name' => 'Turma A & B',
        ]);
        $this->destination = (int) $group->id;

        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();
        $DB->set_field('customfield_field', 'name', 'Vagas & Lugares', ['id' => fields::get_seats_field()->get('id')]);
        fields::reset_field_cache();

        $PAGE->set_url('/local/groupdist/distribute.php');
        $PAGE->set_context($context);
        $form = new options_form(null, [
            'context' => $context,
            'courseid' => (int) $course->id,
            'groupids' => [(int) $group->id],
            'roles' => [],
            'noseats' => $noseats,
            'initialrules' => [],
        ]);
        return $form->render();
    }

    /**
     * The rule builder's cohort list must arrive plain: rules_row.mustache
     * prints each option through a double stash, and rules.js writes search
     * results with textContent. Escaping here would show the teacher
     * "Ciencias &amp; Letras".
     *
     * @return void
     */
    public function test_the_rule_builder_cohort_list_is_not_pre_escaped(): void {
        $html = $this->render_with_cohort();

        // The data-cohorts attribute holds json_encode()d labels, escaped once.
        $this->assertMatchesRegularExpression('/data-cohorts="[^"]*Ciencias &amp; Letras/', $html);
        $this->assertStringNotContainsString('Ciencias &amp;amp; Letras', $html);
    }

    /**
     * The cohortid select must stay escaped, unlike the rule builder's list.
     *
     * Core renders a select's option text through a triple stash
     * (lib/form/templates/element-select.mustache), so the value has to arrive
     * already escaped.
     *
     * @return void
     */
    public function test_the_cohortid_select_stays_escaped(): void {
        $html = $this->render_with_cohort();

        // Scoped to the select's own markup, for the reason given at extract_cohort_select().
        $this->assertSame(
            1,
            preg_match('~<select[^>]*name="cohortid".*?</select>~s', $html, $matches),
            'The cohortid select was not rendered at all.'
        );
        $select = $matches[0];

        $this->assertStringContainsString(
            'Ciencias &amp; Letras',
            $select,
            'The cohortid select renders through a triple stash, so its label must arrive escaped.'
        );
        $this->assertStringNotContainsString('Ciencias & Letras', $select);
    }

    /**
     * The seats label must arrive escaped in the "use seats" checkbox label.
     *
     * Core renders that label through a triple stash (element-advcheckbox),
     * while every other consumer of fields::get_seats_label() wants the plain
     * spelling. Hence its $escape switch, the shape of core's
     * field_controller::get_formatted_name(). The static no-seats note is the
     * second sink; see test_the_seats_label_reaches_the_no_seats_note_escaped().
     *
     * @return void
     */
    public function test_the_seats_label_reaches_the_form_escaped(): void {
        $html = $this->render_with_cohort();

        $this->assertStringContainsString('Vagas &amp; Lugares', $html);
        $this->assertStringNotContainsString('Vagas & Lugares', $html);
    }

    /**
     * The seats label must arrive escaped in the static no-seats note too.
     *
     * Core renders a static element's text through a triple stash
     * (element-static.mustache). The note appears only when some selected
     * groups lack a seats value, so the fixture says one does.
     *
     * @return void
     */
    public function test_the_seats_label_reaches_the_no_seats_note_escaped(): void {
        $html = $this->render_with_cohort(0, 0, 1);

        // Scoped to the note's own markup: the checkbox label above carries the same value.
        $this->assertSame(
            1,
            preg_match('~data-name="noseatsnote">(.*?)</div>~s', $html, $matches),
            'The no-seats note was not rendered at all.'
        );
        $note = $matches[1];

        $this->assertStringContainsString('1 of the 1 selected groups', $note);
        $this->assertStringContainsString('"Vagas &amp; Lugares"', $note);
        $this->assertStringNotContainsString('Vagas & Lugares', $note);
    }

    /**
     * The member filter offers only cohorts that share a member with this
     * course's roster.
     *
     * That bound is why options_form::definition() fetches the member filter's
     * cohorts with no limit, while the rule builder's COHORT_ALL list switches
     * to a search past a small menu; the reasoning is at the call. Changes that
     * must make it fail: widening the mode there to COHORT_ALL or
     * COHORT_WITH_MEMBERS_ONLY.
     *
     * @return void
     */
    public function test_the_member_filter_is_bounded_by_the_roster(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $html = $this->render_with_cohort(3, 4);

        /* The option count below only shows that the off-roster cohorts were
           filtered out if they exist and are visible from this context. */
        $this->assertCount(
            8,
            cohort_get_available_cohorts($this->context, COHORT_ALL, 0, 0),
            'The fixtures did not produce the 8 cohorts this test reasons about.'
        );

        $select = $this->extract_cohort_select($html);

        // Any cohort + Ciencias & Letras + three fillers; the four off-roster ones are absent.
        $this->assertSame(5, substr_count($select, '<option'));
        foreach ($this->offroster as $cohortid) {
            $this->assertStringNotContainsString(
                'value="' . $cohortid . '"',
                $select,
                'A cohort with no member enrolled in this course was offered as a member filter.'
            );
        }
    }

    /**
     * Everything the member filter offers is accepted by the validator, and
     * nothing it did not offer survives a submit.
     *
     * The refused id is an off-roster cohort that cohort_get_cohort() would
     * accept (visible, in a parent context), so only the offer set stops it.
     * Rejection on visibility is covered by
     * test_validation_rejects_a_cohort_the_user_cannot_see(). Changes that must
     * make it fail: widening the mode in options_form::definition().
     *
     * @return void
     */
    public function test_the_form_accepts_only_what_the_picker_offered(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $html = $this->render_with_cohort(0, 2);
        $select = $this->extract_cohort_select($html);

        // Every offered id is one the submit-side validator would accept.
        $this->assertGreaterThan(1, preg_match_all('~<option[^>]*value="(\d+)"~', $select, $found));
        foreach ($found[1] as $offered) {
            if ((int) $offered === 0) {
                continue;
            }
            $this->assertNotFalse(
                cohort_get_cohort((int) $offered, $this->context),
                'The picker offered a cohort the submit-side validator rejects.'
            );
        }

        // And an id it never offered does not survive the form.
        $this->assertNotEmpty($this->offroster);
        $this->assertSame(0, $this->submit_cohortid($this->offroster[0]));

        /* Control: an offered id does survive, so the refusal above is not the
           form rejecting everything. */
        $offeredid = (int) $found[1][array_key_last($found[1])];
        $this->assertGreaterThan(0, $offeredid);
        $this->assertSame($offeredid, $this->submit_cohortid($offeredid));
    }

    /**
     * validation() rejects a cohortid the acting user may not see.
     *
     * Called directly rather than through a submit: a select's exportValue()
     * drops a value matching no option, so through the current element a
     * forged id never reaches validation(). The gate still matters, because an
     * ajax autocomplete's exportValue() returns the submitted value unchecked.
     * Changes that must make it fail: deleting the cohort_get_cohort() check
     * in validation().
     *
     * @return void
     */
    public function test_validation_rejects_a_cohort_the_user_cannot_see(): void {
        $this->render_with_cohort();

        $hidden = $this->getDataGenerator()->create_cohort([
            'contextid' => \core\context\system::instance()->id,
            'visible' => 0,
        ]);
        $visible = $this->getDataGenerator()->create_cohort([
            'contextid' => \core\context\system::instance()->id,
            'visible' => 1,
        ]);
        /* An editing teacher holds moodle/cohort:view in the course, but
           cohort_get_cohort() checks it in the cohort's own context, system
           here; that is what rejects the hidden cohort. Assigning the role at
           system level would grant it and flip the result. */
        $teacher = $this->getDataGenerator()->create_and_enrol(
            get_course($this->context->instanceid),
            'editingteacher'
        );
        $this->setUser($teacher);

        $form = $this->make_form();
        $base = ['overbook' => 0];

        $this->assertArrayHasKey(
            'cohortid',
            $form->validation($base + ['cohortid' => (int) $hidden->id], []),
            'A hidden cohort id was accepted by the form.'
        );

        /* Controls: without them this would pass whenever validation() flags
           cohortid for any reason. */
        $this->assertArrayNotHasKey(
            'cohortid',
            $form->validation($base + ['cohortid' => (int) $visible->id], [])
        );
        $this->assertArrayNotHasKey(
            'cohortid',
            $form->validation($base + ['cohortid' => 0], [])
        );
    }

    /**
     * Build an options form against the last rendered fixture's course.
     *
     * @return options_form The form.
     */
    private function make_form(): options_form {
        global $PAGE;

        $course = get_course($this->context->instanceid);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $PAGE->set_url('/local/groupdist/distribute.php');
        return new options_form(null, [
            'context' => $this->context,
            'courseid' => (int) $course->id,
            'groupids' => [(int) $group->id],
            'roles' => [],
            'noseats' => 0,
            'initialrules' => [],
        ]);
    }

    /**
     * Extract the member filter's own markup from a rendered form.
     *
     * Never assert over the whole page: the rule builder's data-cohorts
     * attribute legitimately carries cohort names further down, so an
     * unscoped match passes while the select is wrong.
     *
     * @param string $html The rendered form.
     * @return string The select element's markup.
     */
    private function extract_cohort_select(string $html): string {
        $this->assertSame(
            1,
            preg_match('~<select[^>]*name="cohortid".*?</select>~s', $html, $matches),
            'The cohortid select was not rendered at all.'
        );
        return $matches[0];
    }

    /**
     * Submit the already-rendered form with one cohortid and read it back.
     *
     * @param int $cohortid The value to post.
     * @return int The cohortid the form yields (0 when it did not survive).
     */
    private function submit_cohortid(int $cohortid): int {
        global $PAGE;

        $course = get_course($this->context->instanceid);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $submitted = [
            'id' => (int) $course->id,
            'groupids' => (string) $group->id,
            'seed' => 1,
            'roleid' => 0,
            'cohortid' => $cohortid,
            'allocateby' => options::ALLOCATE_RANDOM,
            'overbook' => 0,
            'useseats' => 0,
            'previewbutton' => 1,
        ];
        options_form::mock_submit($submitted);

        $PAGE->set_url('/local/groupdist/distribute.php');
        $form = new options_form(null, [
            'context' => $this->context,
            'courseid' => (int) $course->id,
            'groupids' => [(int) $group->id],
            'roles' => [],
            'noseats' => 0,
            'initialrules' => [],
        ]);
        $data = $form->get_data();
        return (int) ($data->cohortid ?? 0);
    }
    /**
     * The rule builder's group list must arrive plain, for the same reason as
     * its cohort list: same template, same double stash. The same group name
     * arrives escaped in a validation error; see
     * test_a_destination_group_is_rejected_as_its_own_source().
     */
    public function test_the_rule_builder_group_list_is_not_pre_escaped(): void {
        $html = $this->render_with_cohort();

        $this->assertMatchesRegularExpression('/data-groups="[^"]*Turma A &amp; B/', $html);
        $this->assertStringNotContainsString('Turma A &amp;amp; B', $html);
    }

    /**
     * The builder is told which groups this run writes into, so it can mark
     * and disable them as rule sources without a round trip.
     */
    public function test_the_builder_receives_the_destination_ids(): void {
        $html = $this->render_with_cohort();

        $this->assertMatchesRegularExpression(
            '/data-destinations="\[' . $this->destination . '\]"/',
            $html,
            'The destination ids did not reach the rule builder.'
        );
    }

    /**
     * A destination group used as its own rule source is rejected while the
     * ignore filter is on — and accepted the moment it is off.
     *
     * The gate is the conjunction, not a ban on the source. With the filter
     * on, candidates::fetch() has already removed every user who could carry
     * the value, so the rule matches nobody; with it off those members take
     * part and the rule is real.
     */
    public function test_a_destination_group_is_rejected_as_its_own_source(): void {
        $this->render_with_cohort();

        $rules = [
            'affinityrulesources' => ['group_' . $this->destination],
            'affinityrulemodes' => [options::AFFINITY_APART],
        ];

        $errors = $this->validate_with($rules, ['ignoregrouped' => 1]);
        $this->assertArrayHasKey('affinityruleserr', $errors);
        /* The message names the rule and the group, in the escaped spelling:
           core renders a form element's error through a triple stash
           (element-template.mustache), unlike the rule builder's data
           attribute. */
        $this->assertStringContainsString('Turma A &amp; B', $errors['affinityruleserr']);
        $this->assertStringNotContainsString('Turma A & B', $errors['affinityruleserr']);
        $this->assertStringContainsString('1', $errors['affinityruleserr']);

        // Control: the same rule with the filter off is accepted.
        $errors = $this->validate_with($rules, []);
        $this->assertArrayNotHasKey('affinityruleserr', $errors);
    }

    /**
     * A group of another course never passes validation, filter or no filter.
     */
    public function test_validation_rejects_a_group_from_another_course(): void {
        $this->render_with_cohort();
        $other = $this->getDataGenerator()->create_course();
        $theirs = $this->getDataGenerator()->create_group(['courseid' => $other->id, 'name' => 'Theirs']);

        $errors = $this->validate_with([
            'affinityrulesources' => ['group_' . $theirs->id],
            'affinityrulemodes' => [options::AFFINITY_APART],
        ], []);
        $this->assertArrayHasKey('affinityruleserr', $errors);

        // Control: a group of this course passes on the same path.
        $mine = $this->getDataGenerator()->create_group(['courseid' => $this->context->instanceid, 'name' => 'Mine']);
        $errors = $this->validate_with([
            'affinityrulesources' => ['group_' . $mine->id],
            'affinityrulemodes' => [options::AFFINITY_APART],
        ], []);
        $this->assertArrayNotHasKey('affinityruleserr', $errors);
    }

    /**
     * The group picker is a menu at the limit and a search past it.
     *
     * Past the limit the menu is deliberately empty (data-groups="[]") and the
     * client switches to the search web service, which in the markup looks
     * like a picker that lost its options, so both sides are asserted.
     */
    public function test_the_group_picker_switches_to_a_search_past_the_limit(): void {
        $this->render_with_cohort();
        $courseid = (int) $this->context->instanceid;

        // One group already exists (the destination); fill up to the limit.
        for ($i = count(profilefields::get_source_groups($this->context)); $i < options_form::GROUP_MENU_LIMIT; $i++) {
            $this->getDataGenerator()->create_group([
                'courseid' => $courseid,
                'name' => sprintf('Filler group %02d', $i),
            ]);
        }
        $this->assertCount(
            options_form::GROUP_MENU_LIMIT,
            profilefields::get_source_groups($this->context),
            'The fixtures did not produce the group count this test reasons about.'
        );

        $html = $this->render_form();
        $this->assertStringContainsString('data-groupsearch="0"', $html);
        $this->assertStringContainsString('Filler group 01', $html);

        // One more flips it.
        $this->getDataGenerator()->create_group(['courseid' => $courseid, 'name' => 'One too many']);
        $html = $this->render_form();
        $this->assertStringContainsString('data-groupsearch="1"', $html);
        $this->assertStringContainsString('data-groups="[]"', $html);
        $this->assertStringNotContainsString('One too many', $html);
    }

    /**
     * A site limit below the default caps the builder and fails validation,
     * so a ruleset the preview would reject never leaves the form.
     *
     * Changes that must make it fail: validation() calling
     * ruleset::from_array() without the resolved limit, or the builder being
     * handed DEFAULT_MAX_RULES instead of it.
     *
     * @return void
     */
    public function test_a_lowered_rule_limit_binds_the_builder_and_validation(): void {
        $this->render_with_cohort();
        set_config('maxaffinityrules', 2, 'local_groupdist');

        $this->assertStringContainsString('data-maxrules="2"', $this->render_form());

        $errors = $this->validate_with([
            'affinityrulesources' => ['city', 'department', 'institution'],
            'affinityrulemodes' => [options::AFFINITY_TOGETHER, options::AFFINITY_APART, options::AFFINITY_APART],
        ], []);
        $this->assertArrayHasKey('affinityruleserr', $errors);

        // Control: at the limit the same kind of ruleset passes.
        $errors = $this->validate_with([
            'affinityrulesources' => ['city', 'department'],
            'affinityrulemodes' => [options::AFFINITY_TOGETHER, options::AFFINITY_APART],
        ], []);
        $this->assertArrayNotHasKey('affinityruleserr', $errors);
    }

    /**
     * A site limit above the default is reachable: the builder offers it and
     * validation accepts a ruleset longer than DEFAULT_MAX_RULES.
     *
     * @return void
     */
    public function test_a_raised_rule_limit_is_reachable(): void {
        global $DB;

        // Four native columns and seven cohorts give eleven distinct sources.
        $this->render_with_cohort(6);
        $cohortids = $DB->get_fieldset_select('cohort', 'id', 'contextid = ?', [\core\context\system::instance()->id]);
        $sources = array_merge(
            options::NATIVE_AFFINITY_FIELDS,
            array_map(static function ($id): string {
                return 'cohort_' . (int) $id;
            }, $cohortids)
        );
        $this->assertCount(\local_groupdist\local\ruleset::DEFAULT_MAX_RULES + 1, $sources);
        $rules = [
            'affinityrulesources' => $sources,
            'affinityrulemodes' => array_fill(0, count($sources), options::AFFINITY_APART),
        ];

        // Control: under the default limit the same ruleset is one rule too many.
        $this->assertArrayHasKey('affinityruleserr', $this->validate_with($rules, []));

        set_config('maxaffinityrules', count($sources), 'local_groupdist');
        $this->assertStringContainsString('data-maxrules="' . count($sources) . '"', $this->render_form());
        $this->assertArrayNotHasKey('affinityruleserr', $this->validate_with($rules, []));
    }

    /**
     * Render the options form again over the current fixture course.
     *
     * @return string The rendered HTML.
     */
    private function render_form(): string {
        global $PAGE;

        $PAGE->set_context($this->context);
        $form = new options_form(null, [
            'context' => $this->context,
            'courseid' => (int) $this->context->instanceid,
            'groupids' => [$this->destination],
            'roles' => [],
            'noseats' => 0,
            'initialrules' => [],
        ]);
        return $form->render();
    }

    /**
     * Run validation() against a flattened rule POST.
     *
     * The builder's rows are not registered form elements, so they never reach
     * $data — options::rules_from_post() reads them straight from the
     * superglobal, which is what this has to seed.
     *
     * @param array $rules The affinityrulesources/affinityrulemodes arrays.
     * @param array $data The $data validation() is called with.
     * @return array The errors.
     */
    private function validate_with(array $rules, array $data): array {
        global $PAGE;

        $_POST['affinityrulesources'] = $rules['affinityrulesources'];
        $_POST['affinityrulemodes'] = $rules['affinityrulemodes'];
        $PAGE->set_context($this->context);
        $form = new options_form(null, [
            'context' => $this->context,
            'courseid' => (int) $this->context->instanceid,
            'groupids' => [$this->destination],
            'roles' => [],
            'noseats' => 0,
            'initialrules' => [],
        ]);
        try {
            return $form->validation($data + ['groupids' => [$this->destination]], []);
        } finally {
            unset($_POST['affinityrulesources'], $_POST['affinityrulemodes']);
        }
    }
}
