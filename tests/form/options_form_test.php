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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Distribution options form: the two cohort menus escape their labels
 * differently on purpose, and this pins that apart.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\form\options_form::class)]
final class options_form_test extends \advanced_testcase {
    /** @var \core\context\course|null Course context of the last rendered form. */
    private ?\core\context\course $context = null;

    /** @var array Ids of the cohorts seeded with no member enrolled in the course. */
    private array $offroster = [];

    /**
     * A course, a cohort whose name contains an ampersand, and the rendered
     * options form.
     *
     * Extra cohorts all share the SINGLE enrolled user: the member filter's
     * "HAVING COUNT(DISTINCT u.id) > 0" groups per cohort, so one user who is
     * enrolled in the course and a member of every cohort makes all of them
     * eligible. They are named "Filler NN" so the ordering
     * (cohort/lib.php: ORDER BY cohort.name) keeps "Ciencias & Letras" in
     * place for the escaping tests.
     *
     * @param int $extracohorts Additional eligible cohorts to seed.
     * @param int $offroster Additional cohorts with NO enrolled member.
     * @return string The rendered form HTML.
     */
    private function render_with_cohort(int $extracohorts = 0, int $offroster = 0): string {
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
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

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
            'noseats' => 0,
            'initialrules' => [],
        ]);
        return $form->render();
    }

    /**
     * The rule builder's cohort list must arrive PLAIN: its own template
     * prints each option through a Mustache double stash, and rules.js writes
     * search results with textContent. Escaping here would show the teacher
     * "Ciencias &amp;amp; Letras" on the first screen of the flow.
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
     * The cohortid SELECT must stay escaped, and this is the assertion that
     * stops someone "fixing" it to match its neighbour.
     *
     * Core renders a select's options through a TRIPLE stash
     * (lib/form/templates/element-select.mustache: option text is {{{text}}}),
     * so the value has to arrive already escaped. Same widget, same page, and
     * the opposite requirement from the rule builder list above.
     *
     * @return void
     */
    public function test_the_cohortid_select_stays_escaped(): void {
        $html = $this->render_with_cohort();

        /* Scoped to the select's own markup. An unanchored regex over the
           whole page passes even when the select is wrong, because the rule
           builder's data-cohorts attribute further down carries the escaped
           spelling legitimately — the first draft of this test did exactly
           that and survived the mutation it exists to catch. */
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
     * The seats label must arrive ESCAPED at this form's two sinks, and this
     * is the assertion that stops the opposite mistake.
     *
     * Commit 8676cba flipped fields::get_seats_label() to the plain spelling
     * for its many double-stash consumers and put a raw ampersand into these
     * two, which core renders through {{{label}}} (element-advcheckbox) and
     * {{{element.html}}} (element-static). Hence the $escape switch, which
     * core draws the same way in field_controller::get_formatted_name().
     *
     * @return void
     */
    public function test_the_seats_label_reaches_the_form_escaped(): void {
        $html = $this->render_with_cohort();

        $this->assertStringContainsString('Vagas &amp; Lugares', $html);
        $this->assertStringNotContainsString('Vagas & Lugares', $html);
    }

    /**
     * The member filter offers only cohorts that share a member with this
     * course's roster — the bound the unbounded call at definition() rests on.
     *
     * This is what makes the decision recorded there enforceable rather than
     * prose. The plugin's never-enumerate rule governs the rule builder, whose
     * COHORT_ALL call really would list the platform's whole cohort table; the
     * member filter is allowed to be unbounded precisely BECAUSE
     * COHORT_WITH_ENROLLED_MEMBERS_ONLY narrows it to the roster first.
     *
     * Mutation: widen the mode at options_form::definition() to COHORT_ALL (or
     * COHORT_WITH_MEMBERS_ONLY) and the off-roster cohorts appear as options.
     *
     * @return void
     */
    public function test_the_member_filter_is_bounded_by_the_roster(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $html = $this->render_with_cohort(3, 4);

        /* Fixture-drift guard: the option count below only means "the
           off-roster cohorts were filtered out" if they were really created
           and really visible from this context. Without this, a generator
           change that stopped producing them would leave the assertion
           passing while testing nothing. */
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
     * The two halves of the invariant this form has to keep: everything the
     * picker offers is something the validator accepts, and nothing it did
     * not offer survives a submit.
     *
     * The negative case is deliberately a cohort the validator WOULD accept —
     * an off-roster one is visible and in a parent context, so
     * cohort_get_cohort() says yes to it. What stops it is the offer set, and
     * that is the point: the picker's list is load-bearing on the submit path
     * too, not only on screen. Rejection for a visibility reason is the other
     * mechanism and is covered by
     * test_validation_rejects_a_cohort_the_user_cannot_see.
     *
     * Mutation: widen the mode at definition() and the off-roster cohort both
     * appears as an option and survives the submit.
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

        /* Control: a legitimately offered id does survive, so the assertion
           above is not passing because the form rejects everything — which is
           what a broken fixture or a failing unrelated validator would look
           like from here. */
        $offeredid = (int) $found[1][array_key_last($found[1])];
        $this->assertGreaterThan(0, $offeredid);
        $this->assertSame($offeredid, $this->submit_cohortid($offeredid));
    }

    /**
     * validation() rejects a cohortid the acting user may not see.
     *
     * Called directly rather than through a submit, because the gate is
     * deliberately unreachable via the current element: a plain select's
     * exportValue() drops a value matching no registered option, so a forged
     * id never reaches validation() at all. That is exactly why the gate is
     * written down — the protection is an accident of PEAR, and it evaporates
     * the moment the element type changes (an ajax autocomplete's
     * exportValue() returns the submitted value unchecked). This pins the
     * gate itself so the accident is no longer the only thing holding.
     *
     * Mutation: delete the cohort_get_cohort() branch in validation().
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
        /* An editing teacher DOES hold moodle/cohort:view, but the capability
           is declared at CONTEXT_COURSE and their role is assigned in the
           course — while cohort_get_cohort() checks it at the COHORT's own
           context, which is system here. That is what rejects the hidden
           cohort; do not "simplify" this fixture by assigning the role at
           system level, which would grant it and flip the result. */
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

        /* Controls. Without them this passes whenever validation() errors on
           everything — including on the cohortid key for an unrelated reason. */
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
}
