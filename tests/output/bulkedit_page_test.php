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

namespace local_groupdist\output;

use local_groupdist\local\fields;

/**
 * Bulk edit row context tests.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\output\bulkedit_page
 */
final class bulkedit_page_test extends \advanced_testcase {
    /**
     * Names reach the row context unescaped, because every consumer escapes
     * for itself: Mustache double stashes on the page, textContent in
     * bulkedit.js after the settings modal saves. Escaping here shows the
     * ampersand twice on load and once after a save.
     *
     * @return void
     */
    public function test_group_names_are_not_pre_escaped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name' => 'Ana & Bruno',
        ]);

        $row = bulkedit_page::build_row($group, [], [], 0);

        $this->assertSame('Ana & Bruno', $row['name']);
        $this->assertStringNotContainsString('&amp;', $row['name']);
        $this->assertSame('A', $row['initial']);
        /* The initial is asserted separately below, with a fixture whose
           FIRST character actually differs between the two spellings. An
           ampersand does not: '&' escapes to '&amp;', which still starts
           with '&'. */
    }

    /**
     * The whole page renders with each name escaped exactly once.
     *
     * This is the assertion that would have caught the defect the unit tests
     * above miss by construction: the seats label does not reach the template
     * through a plain double stash but as a {{#str}} parameter, and the string
     * helper renders that parameter through a double stash of its own before
     * substituting it, while the lambda's return is inserted unescaped. Only a
     * real render exercises that. It also covers any consumer added later —
     * a new template line escaping an already-escaped label fails here without
     * anyone having to remember this rule.
     *
     * @return void
     */
    public function test_the_rendered_page_escapes_every_name_exactly_once(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name' => 'Ana & Bruno',
        ]);

        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();
        $DB->set_field('customfield_field', 'name', 'Vagas & Lugares', ['id' => fields::get_seats_field()->get('id')]);
        fields::reset_field_cache();

        $PAGE->set_url('/local/groupdist/bulkedit.php');
        $PAGE->set_context(\core\context\course::instance($course->id));
        $renderer = $PAGE->get_renderer('core');
        $page = new bulkedit_page($course, [$group]);
        $html = $renderer->render_from_template('local_groupdist/bulkedit', $page->export_for_template($renderer));

        $this->assertStringContainsString('Vagas &amp; Lugares', $html);
        $this->assertStringContainsString('Ana &amp; Bruno', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html, 'A name reached the page escaped twice.');
    }

    /**
     * The avatar initial is sliced from the same unescaped name, so it is
     * never the first character of an entity instead of the first character
     * of the name.
     *
     * The fixture leads with a greater-than sign because that is a character
     * whose FIRST-character spelling actually differs: escaping turns it into
     * "&gt;". An ampersand cannot show this — "&" escapes to "&amp;", which
     * still starts with "&" — and a tag-shaped fixture cannot either, since
     * format_string strips tags in both modes.
     *
     * @return void
     */
    public function test_the_initial_comes_from_the_unescaped_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name' => '> Alpha squad',
        ]);

        $row = bulkedit_page::build_row($group, [], [], 0);

        $this->assertSame('> Alpha squad', $row['name']);
        $this->assertSame('>', $row['initial']);
    }

    /**
     * Column headers come from admin-editable field names and carry the same
     * rule.
     *
     * @return void
     */
    public function test_column_labels_are_not_pre_escaped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();

        $field = fields::get_seats_field();
        $field->set('name', 'Vagas & Lugares');
        $field->save();
        fields::reset_field_cache();

        $columns = bulkedit_page::get_field_columns();
        $labels = array_column($columns, 'label', 'shortname');

        $this->assertArrayHasKey(fields::SHORTNAME_SEATS, $labels);
        $this->assertSame('Vagas & Lugares', $labels[fields::SHORTNAME_SEATS]);
    }
}
