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
    /**
     * A course, a cohort whose name contains an ampersand, and the rendered
     * options form.
     *
     * @return string The rendered form HTML.
     */
    private function render_with_cohort(): string {
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
}
