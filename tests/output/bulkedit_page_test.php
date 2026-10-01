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

use core_group\customfield\group_handler;
use local_groupdist\local\fields;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Bulk edit row context tests.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\output\bulkedit_page::class)]
final class bulkedit_page_test extends \advanced_testcase {
    /**
     * Names reach the row context unescaped, because every consumer escapes
     * for itself ({@see bulkedit_page::plain()}).
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
        /* Both spellings of this name start with A, so
           test_the_initial_comes_from_the_unescaped_name pins the initial with
           a fixture whose first character escaping changes. */
    }

    /**
     * The whole page renders with each name escaped exactly once.
     *
     * Only a real render covers the seats label: it reaches the template as a
     * {{#str}} parameter, which the string helper escapes through a double
     * stash of its own before substituting it, and the helper's return is
     * inserted unescaped. It also catches a template line added later that
     * escapes an already-escaped label.
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
     * With formatstringstriptags off, group names and column labels still
     * reach the row context plain
     * ({@see \local_groupdist\local\plaintext::format()}).
     *
     * @return void
     */
    public function test_names_are_plain_with_formatstringstriptags_off(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('formatstringstriptags', 0);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $ampersand = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Ana & Bruno']);
        $angled = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Turma <3 anos']);
        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();
        $DB->set_field('customfield_field', 'name', 'Vagas & Lugares', ['id' => fields::get_seats_field()->get('id')]);
        fields::reset_field_cache();

        $this->assertSame('Ana & Bruno', bulkedit_page::build_row($ampersand, [], [], 0)['name']);
        $this->assertSame('Turma ', bulkedit_page::build_row($angled, [], [], 0)['name']);
        $labels = array_column(bulkedit_page::get_field_columns(), 'label', 'shortname');
        $this->assertSame('Vagas & Lugares', $labels[fields::SHORTNAME_SEATS]);
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

    /**
     * Create a group custom field.
     *
     * @param string $type The field type.
     * @param string $shortname The field shortname.
     * @param array $configdata Field configuration on top of the generator's defaults.
     * @return \core_customfield\field_controller The field.
     */
    private function create_group_field(
        string $type,
        string $shortname,
        array $configdata = []
    ): \core_customfield\field_controller {
        $cfgenerator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgenerator->create_category(['component' => 'core_group', 'area' => 'group', 'itemid' => 0]);
        return $cfgenerator->create_field([
            'categoryid' => $category->get('id'),
            'type' => $type,
            'shortname' => $shortname,
            'configdata' => $configdata,
        ]);
    }

    /**
     * A number field whose value a provider computes is shown read-only, as
     * core's group form shows it, and a hand-typed number field beside it
     * stays inline-editable.
     *
     * Core's only provider, nofactivities, is offered for course fields alone,
     * so such a group field exists only through a provider another plugin
     * registers with the add_custom_providers hook; the test registers one
     * that way and first checks core offers it for a group field. Changes that
     * must make it fail: dropping the is_editable() check from
     * get_field_columns().
     *
     * @return void
     */
    public function test_a_provider_backed_number_field_is_read_only(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/groupdist/tests/fixtures/group_number_provider.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->redirectHook(
            \customfield_number\hook\add_custom_providers::class,
            static function (\customfield_number\hook\add_custom_providers $hook): void {
                $hook->add_provider(new \local_groupdist\fixtures\group_number_provider($hook->field));
            }
        );
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $typed = $this->create_group_field('number', 'typed', ['decimalplaces' => 0]);

        // Precondition: core offers the hook's provider for a group field, and not its own.
        $offered = array_map('get_class', array_values(\customfield_number\provider_base::get_all_providers($typed)));
        $this->assertSame([\local_groupdist\fixtures\group_number_provider::class], $offered);

        $this->create_group_field('number', 'computed', [
            'fieldtype' => \local_groupdist\fixtures\group_number_provider::class,
            'decimalplaces' => 0,
        ]);
        group_handler::create()->instance_form_save((object) ['id' => $group->id, 'customfield_computed' => 7]);

        $columns = array_column(bulkedit_page::get_field_columns(), null, 'shortname');
        $this->assertTrue($columns['computed']['isreadonly']);
        $this->assertFalse($columns['computed']['isnumber']);
        $this->assertFalse($columns['typed']['isreadonly']);
        $this->assertTrue($columns['typed']['isnumber']);

        $data = group_handler::create()->get_instances_data([(int) $group->id], true);
        $row = bulkedit_page::build_row($group, array_values($columns), $data[(int) $group->id], 0);
        $cells = array_column($row['cells'], null, 'shortname');
        $this->assertSame('7', $cells['computed']['displayvalue']);
        $this->assertSame('', $cells['computed']['value']);
    }

    /**
     * Each number input states the floor the inline save enforces and the
     * step its field allows: seats never below 0 and whole, even with the
     * field's own minimum cleared; another field its configured minimum and
     * any decimals; a field without a minimum no min attribute at all.
     *
     * Each assertion reads the input inside its own cell, never the whole
     * page, since every number input shares the same markup.
     *
     * @return void
     */
    public function test_number_inputs_state_the_bounds_the_save_enforces(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();
        $seats = fields::get_seats_field();
        group_handler::create()->save_field_configuration($seats, (object) [
            'configdata' => ['minimumvalue' => ''] + $seats->get('configdata'),
        ]);
        fields::reset_field_cache();
        $this->create_group_field('number', 'quota', ['decimalplaces' => 2, 'minimumvalue' => 2]);
        $this->create_group_field('number', 'temperature', ['decimalplaces' => 1, 'minimumvalue' => '']);

        $PAGE->set_url('/local/groupdist/bulkedit.php');
        $PAGE->set_context(\core\context\course::instance($course->id));
        $renderer = $PAGE->get_renderer('core');
        $page = new bulkedit_page($course, [$group]);
        $html = $renderer->render_from_template('local_groupdist/bulkedit', $page->export_for_template($renderer));

        $seatsinput = $this->number_input($html, fields::SHORTNAME_SEATS);
        $this->assertStringContainsString('min="0"', $seatsinput);
        $this->assertStringContainsString('step="1"', $seatsinput);

        $quotainput = $this->number_input($html, 'quota');
        $this->assertStringContainsString('min="2"', $quotainput);
        $this->assertStringContainsString('step="any"', $quotainput);

        $temperatureinput = $this->number_input($html, 'temperature');
        $this->assertStringNotContainsString('min=', $temperatureinput);
        $this->assertStringContainsString('step="any"', $temperatureinput);
    }

    /**
     * The number input inside one field's cell of the rendered table.
     *
     * @param string $html The rendered page.
     * @param string $shortname The field shortname.
     * @return string The input tag.
     */
    private function number_input(string $html, string $shortname): string {
        $cell = '/<td data-colkey="cf_' . preg_quote($shortname, '/') . '"[^>]*>.*?<\/td>/s';
        $this->assertSame(1, preg_match($cell, $html, $cellmatch), "No cell for {$shortname}.");
        $this->assertSame(1, preg_match('/<input type="number"[^>]*>/', $cellmatch[0], $inputmatch));
        return $inputmatch[0];
    }
}
