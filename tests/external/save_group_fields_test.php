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

namespace local_groupdist\external;

use core_external\external_api;
use local_groupdist\local\fields;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Bulk save web service tests.
 *
 * PHPUnit metadata is written as docblock tags, never attributes: Moodle 4.5
 * runs PHPUnit 9, which reads only the tags, and its moodle-cs reports every
 * test of a class without a covers tag.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\external\save_group_fields
 */
final class save_group_fields_test extends \externallib_advanced_testcase {
    /**
     * Course with two groups, provisioned fields, and an editing teacher.
     *
     * @return array [course, group1, group2, teacher].
     */
    private function make_course(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();
        return [$course, $group1, $group2, $teacher];
    }

    /**
     * Call the function through the full external stack.
     *
     * @param int $courseid The course id.
     * @param array $changes The changes.
     * @return array The raw response.
     */
    private function call(int $courseid, array $changes): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function(
            'local_groupdist_save_group_fields',
            ['courseid' => $courseid, 'changes' => $changes]
        );
    }

    /**
     * Changed cells persist; untouched fields stay untouched.
     */
    public function test_save_changes(): void {
        $this->resetAfterTest();
        [$course, $group1, $group2, $teacher] = $this->make_course();
        $this->setUser($teacher);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '12'],
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_LOCATION, 'value' => 'Room 101'],
            ['groupid' => (int) $group2->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '8'],
        ]);
        $this->assertFalse($response['error']);
        $this->assertCount(3, $response['data']['saved']);

        fields::reset_field_cache();
        $values = fields::get_group_values([(int) $group1->id, (int) $group2->id]);
        $this->assertSame(12, $values[(int) $group1->id]->seats);
        $this->assertSame('Room 101', $values[(int) $group1->id]->location);
        $this->assertSame(8, $values[(int) $group2->id]->seats);
        // Untouched: group2's location stays unset, as only sent cells are written.
        $this->assertNull($values[(int) $group2->id]->location);
    }

    /**
     * An empty seats value unsets the field.
     */
    public function test_empty_number_unsets(): void {
        $this->resetAfterTest();
        [$course, $group1, , $teacher] = $this->make_course();
        $this->setUser($teacher);

        $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '5'],
        ]);
        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => ''],
        ]);
        $this->assertFalse($response['error']);

        fields::reset_field_cache();
        $values = fields::get_group_values([(int) $group1->id]);
        $this->assertNull($values[(int) $group1->id]->seats);
    }

    /**
     * Assert that a call failed as a whole with invalid_parameter_exception,
     * for the reason given.
     *
     * Several refusals share the 'invalidparameter' error code, so the
     * exception's debug info, which carries the reason, tells them apart.
     *
     * @param array $response The raw response.
     * @param string $reason Text the exception's debug info must contain.
     * @return void
     */
    private function assert_invalid_parameter(array $response, string $reason): void {
        $this->assertTrue($response['error']);
        $this->assertSame('invalidparameter', $response['exception']->errorcode);
        $this->assertStringContainsString($reason, $response['exception']->debuginfo ?? '');
    }

    /**
     * The per-call cap is enforced server-side — the payload cannot grow unbounded.
     */
    public function test_change_cap_enforced(): void {
        $this->resetAfterTest();
        [$course, $group1, , $teacher] = $this->make_course();
        $this->setUser($teacher);

        $changes = [];
        for ($i = 0; $i <= save_group_fields::MAX_CHANGES; $i++) {
            $changes[] = ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '1'];
        }
        $response = $this->call((int) $course->id, $changes);
        $this->assert_invalid_parameter($response, 'Too many changes in one call');

        // Control: exactly the cap is accepted.
        $response = $this->call((int) $course->id, array_slice($changes, 0, save_group_fields::MAX_CHANGES));
        $this->assertSame([], $this->refused($response));
    }

    /**
     * The capability gate is real: a student is rejected.
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();
        [$course, $group1, , $teacher] = $this->make_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $changes = [
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '5'],
        ];

        $this->setUser($student);
        $response = $this->call((int) $course->id, $changes);
        $this->assertTrue($response['error']);
        $this->assertSame('nopermissions', $response['exception']->errorcode);
        $this->assertNull($this->stored(fields::SHORTNAME_SEATS, (int) $group1->id));

        // Control: the same call passes the gate for a managegroups holder.
        $this->setUser($teacher);
        $this->assertSame([], $this->refused($this->call((int) $course->id, $changes)));
        $this->assertSame(5.0, (float) $this->stored(fields::SHORTNAME_SEATS, (int) $group1->id)->decvalue);
    }

    /**
     * Create a group custom field.
     *
     * Call it after make_course(): the generator saves through the cached
     * group handler, which then re-reads its fields, so the save sees the new
     * column.
     *
     * @param string $type The field type.
     * @param string $shortname The field shortname.
     * @param array $configdata Field configuration on top of the generator's defaults.
     * @return void
     */
    private function create_group_field(string $type, string $shortname, array $configdata = []): void {
        $cfgenerator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgenerator->create_category([
            'component' => 'core_group',
            'area' => 'group',
            'itemid' => 0,
        ]);
        $cfgenerator->create_field([
            'categoryid' => $category->get('id'),
            'type' => $type,
            'shortname' => $shortname,
            'configdata' => $configdata,
        ]);
    }

    /**
     * Stored value of one group custom field, read from the table.
     *
     * @param string $shortname The field shortname.
     * @param int $groupid The group id.
     * @return \stdClass|null The customfield_data row, or null when nothing was written.
     */
    private function stored(string $shortname, int $groupid): ?\stdClass {
        global $DB;
        $fieldid = $DB->get_field('customfield_field', 'id', ['shortname' => $shortname], MUST_EXIST);
        return $DB->get_record('customfield_data', ['fieldid' => $fieldid, 'instanceid' => $groupid]) ?: null;
    }

    /**
     * Index a response's refused cells by "groupid:shortname".
     *
     * @param array $response The raw response.
     * @return array Map of "groupid:shortname" => message.
     */
    private function refused(array $response): array {
        $this->assertFalse($response['error'], $response['exception']->message ?? '');
        $refused = [];
        foreach ($response['data']['errors'] as $error) {
            $refused[$error['groupid'] . ':' . $error['shortname']] = $error['message'];
        }
        return $refused;
    }

    /**
     * A group of another course still fails the whole call: it is a malformed
     * request, not a value to correct.
     */
    public function test_rejects_a_group_of_another_course(): void {
        $this->resetAfterTest();
        [$course, $group1, , $teacher] = $this->make_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $foreign = $this->getDataGenerator()->create_group(['courseid' => $othercourse->id]);
        $this->setUser($teacher);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $foreign->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '5'],
        ]);
        $this->assert_invalid_parameter($response, 'Group not in course: ' . $foreign->id);
        $this->assertNull($this->stored(fields::SHORTNAME_SEATS, (int) $foreign->id));

        // Control: the same cell for a group of the course is written.
        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '5'],
        ]);
        $this->assertSame([], $this->refused($response));
        $this->assertSame(5.0, (float) $this->stored(fields::SHORTNAME_SEATS, (int) $group1->id)->decvalue);
    }

    /**
     * A group the caller may not see is refused like a group of another course,
     * also on a cold core/coursehiddengroups cache, where groups_get_all_groups()
     * would return it ({@see \local_groupdist\local\distribution::get_destination_groups()}).
     *
     * create_group() warms that cache entry, so it is purged before each call.
     * Changes that must make it fail: resolving the course's groups in
     * save_group_fields through groups_get_all_groups() again.
     *
     * @return void
     */
    public function test_a_hidden_group_is_refused_on_a_cold_cache(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $group1, , $teacher] = $this->make_course();
        $hidden = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'visibility' => GROUPS_VISIBILITY_NONE,
        ]);
        $context = \core\context\course::instance($course->id);
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/course:viewhiddengroups', CAP_PROHIBIT, $roleid, $context->id, true);
        $this->setUser($teacher);
        $this->assertFalse(has_capability('moodle/course:viewhiddengroups', $context));

        \cache_helper::purge_by_definition('core', 'coursehiddengroups');
        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $hidden->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '5'],
        ]);
        $this->assert_invalid_parameter($response, 'Group not in course: ' . $hidden->id);
        $this->assertNull($this->stored(fields::SHORTNAME_SEATS, (int) $hidden->id));

        // Control: the same caller writes a group every participant may see.
        \cache_helper::purge_by_definition('core', 'coursehiddengroups');
        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '5'],
        ]);
        $this->assertSame([], $this->refused($response));

        // Control: a viewhiddengroups holder writes the hidden group.
        $this->setAdminUser();
        \cache_helper::purge_by_definition('core', 'coursehiddengroups');
        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $hidden->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '6'],
        ]);
        $this->assertSame([], $this->refused($response));
        $this->assertSame(6.0, (float) $this->stored(fields::SHORTNAME_SEATS, (int) $hidden->id)->decvalue);
    }

    /**
     * Clear the seats field's configured minimum, as an admin can on the group
     * custom fields page, through the handler so its cached fields see it.
     *
     * @return void
     */
    private function clear_seats_minimum(): void {
        $handler = \core_group\customfield\group_handler::create();
        $seats = fields::get_seats_field();
        $handler->save_field_configuration($seats, (object) [
            'configdata' => ['minimumvalue' => ''] + $seats->get('configdata'),
        ]);
        fields::reset_field_cache();

        foreach ($handler->get_fields() as $field) {
            if ($field->get('shortname') === fields::SHORTNAME_SEATS) {
                $this->assertSame('', (string) $field->get_configdata_property('minimumvalue'));
                return;
            }
        }
        $this->fail('The seats field is missing.');
    }

    /**
     * A number that does not parse is refused per cell, and the valid cell
     * beside it is saved.
     *
     * The non-negative rule is the seats field's alone: with the seats
     * minimum cleared, a negative seat count is still refused, by this
     * plugin's rule rather than core's minimum check, while a negative value
     * in a number field that declares no minimum is written.
     */
    public function test_malformed_numbers_and_negative_seats_are_refused_per_cell(): void {
        $this->resetAfterTest();
        [$course, $group1, $group2, $teacher] = $this->make_course();
        $this->create_group_field('number', 'temperature', ['decimalplaces' => 0, 'minimumvalue' => '', 'maximumvalue' => '']);
        $this->clear_seats_minimum();
        $this->setUser($teacher);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => 'temperature', 'value' => 'abc'],
            ['groupid' => (int) $group2->id, 'shortname' => 'temperature', 'value' => '-3'],
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '-1'],
            ['groupid' => (int) $group2->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '9'],
        ]);

        $refused = $this->refused($response);
        $this->assertSame(get_string('err_numeric', 'form'), $refused[$group1->id . ':temperature'] ?? null);
        $this->assertSame(
            get_string('minimumvalueerror', 'customfield_number', 0),
            $refused[$group1->id . ':' . fields::SHORTNAME_SEATS] ?? null
        );
        $this->assertCount(2, $refused);
        $this->assertNull($this->stored('temperature', (int) $group1->id));
        $this->assertNull($this->stored(fields::SHORTNAME_SEATS, (int) $group1->id));

        // Control: the valid cells of the same call were written, the negative temperature among them.
        $this->assertSame(-3.0, (float) $this->stored('temperature', (int) $group2->id)->decvalue);
        $this->assertSame(9.0, (float) $this->stored(fields::SHORTNAME_SEATS, (int) $group2->id)->decvalue);
    }

    /**
     * A seat count must be a whole number, while another number field keeps
     * the decimal places it is configured with.
     */
    public function test_seats_must_be_a_whole_number(): void {
        $this->resetAfterTest();
        [$course, $group1, $group2, $teacher] = $this->make_course();
        $this->create_group_field('number', 'weight', ['decimalplaces' => 2, 'minimumvalue' => '', 'maximumvalue' => '']);
        $this->setUser($teacher);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '2.5'],
            ['groupid' => (int) $group2->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '3'],
            ['groupid' => (int) $group1->id, 'shortname' => 'weight', 'value' => '2.5'],
        ]);

        $refused = $this->refused($response);
        $this->assertSame(
            get_string('errorseatswhole', 'local_groupdist'),
            $refused[$group1->id . ':' . fields::SHORTNAME_SEATS] ?? null
        );
        $this->assertCount(1, $refused);
        $this->assertNull($this->stored(fields::SHORTNAME_SEATS, (int) $group1->id));

        // Control: a whole seat count and a fractional value in another field are written.
        $this->assertSame(3.0, (float) $this->stored(fields::SHORTNAME_SEATS, (int) $group2->id)->decvalue);
        $this->assertSame(2.5, (float) $this->stored('weight', (int) $group1->id)->decvalue);
    }

    /**
     * A number at or above what the field can store is refused even when the
     * field declares no maximum, with the message of core's own ceiling rule
     * (SQL_INT_MAX + 1 where core has one).
     *
     * The control writes the largest whole number below the ceiling and reads
     * it back, which fails with a database error if the ceiling were above
     * what {customfield_data}.decvalue holds.
     */
    public function test_number_above_the_form_ceiling_is_refused(): void {
        $this->resetAfterTest();
        [$course, $group1, $group2, $teacher] = $this->make_course();
        $this->create_group_field('number', 'budget', ['decimalplaces' => 0, 'minimumvalue' => '', 'maximumvalue' => '']);
        $this->setUser($teacher);
        $ceiling = fields::number_ceiling();

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => 'budget', 'value' => (string) $ceiling],
            ['groupid' => (int) $group2->id, 'shortname' => 'budget', 'value' => (string) ($ceiling - 1)],
        ]);

        $refused = $this->refused($response);
        $this->assertSame(
            get_string('maximumvalueerror', 'customfield_number', $ceiling - 1),
            $refused[$group1->id . ':budget'] ?? null
        );
        $this->assertCount(1, $refused);
        $this->assertNull($this->stored('budget', (int) $group1->id));
        // Control: the largest value the form accepts was written.
        $this->assertSame($ceiling - 1, (float) $this->stored('budget', (int) $group2->id)->decvalue);
    }

    /**
     * A number outside the field's configured range is refused with core's
     * own message, as the group form would refuse it.
     */
    public function test_number_outside_the_field_range_is_refused(): void {
        $this->resetAfterTest();
        [$course, $group1, $group2, $teacher] = $this->make_course();
        $this->create_group_field('number', 'quota', ['decimalplaces' => 0, 'minimumvalue' => 2, 'maximumvalue' => 10]);
        $this->setUser($teacher);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => 'quota', 'value' => '11'],
            ['groupid' => (int) $group2->id, 'shortname' => 'quota', 'value' => '1'],
        ]);
        $refused = $this->refused($response);
        $this->assertSame(
            get_string('maximumvalueerror', 'customfield_number', format_float(10, 0)),
            $refused[$group1->id . ':quota'] ?? null
        );
        $this->assertSame(
            get_string('minimumvalueerror', 'customfield_number', format_float(2, 0)),
            $refused[$group2->id . ':quota'] ?? null
        );
        $this->assertNull($this->stored('quota', (int) $group1->id));
        $this->assertNull($this->stored('quota', (int) $group2->id));

        // Control: a value inside the range is written.
        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => 'quota', 'value' => '10'],
        ]);
        $this->assertSame([], $this->refused($response));
        $this->assertSame(10.0, (float) $this->stored('quota', (int) $group1->id)->decvalue);
    }

    /**
     * A text longer than the field's maximum length is refused; the browser's
     * maxlength attribute binds nobody calling the web service.
     */
    public function test_text_longer_than_the_field_allows_is_refused(): void {
        $this->resetAfterTest();
        [$course, $group1, $group2, $teacher] = $this->make_course();
        $this->create_group_field('text', 'roomcode', ['maxlength' => 5, 'displaysize' => 5]);
        $this->setUser($teacher);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => 'roomcode', 'value' => 'ABCDEFG'],
            ['groupid' => (int) $group2->id, 'shortname' => 'roomcode', 'value' => 'ABC'],
        ]);

        $refused = $this->refused($response);
        $this->assertSame(get_string('errormaxlength', 'customfield_text', 5), $refused[$group1->id . ':roomcode'] ?? null);
        $this->assertCount(1, $refused);
        $this->assertNull($this->stored('roomcode', (int) $group1->id));
        // Control: the short value was written.
        $this->assertSame('ABC', $this->stored('roomcode', (int) $group2->id)->charvalue);
    }

    /**
     * A required field cannot be cleared inline, as the group form's required
     * rule forbids it.
     */
    public function test_a_required_field_cannot_be_cleared(): void {
        $this->resetAfterTest();
        [$course, $group1, $group2, $teacher] = $this->make_course();
        $this->create_group_field('text', 'tutor', ['required' => 1, 'maxlength' => 50, 'displaysize' => 20]);
        $this->setUser($teacher);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => 'tutor', 'value' => ''],
            ['groupid' => (int) $group2->id, 'shortname' => 'tutor', 'value' => 'Ana'],
        ]);

        $refused = $this->refused($response);
        $this->assertSame(get_string('err_required', 'form'), $refused[$group1->id . ':tutor'] ?? null);
        $this->assertCount(1, $refused);
        $this->assertNull($this->stored('tutor', (int) $group1->id));
        // Control: a value for the same required field was written.
        $this->assertSame('Ana', $this->stored('tutor', (int) $group2->id)->charvalue);
    }

    /**
     * A unique-values field refuses a value another group already holds, also
     * when that group was written earlier in the same call.
     */
    public function test_unique_values_hold_within_one_call(): void {
        $this->resetAfterTest();
        [$course, $group1, $group2, $teacher] = $this->make_course();
        $this->create_group_field('text', 'badge', ['uniquevalues' => 1, 'maxlength' => 20, 'displaysize' => 20]);
        $this->setUser($teacher);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => 'badge', 'value' => 'Blue'],
            ['groupid' => (int) $group2->id, 'shortname' => 'badge', 'value' => 'Blue'],
        ]);

        $refused = $this->refused($response);
        $this->assertSame(get_string('erroruniquevalues', 'core_customfield'), $refused[$group2->id . ':badge'] ?? null);
        $this->assertCount(1, $refused);
        $this->assertSame('Blue', $this->stored('badge', (int) $group1->id)->charvalue);
        $this->assertNull($this->stored('badge', (int) $group2->id));
    }

    /**
     * Fields that are not inline-editable (e.g. textarea) are rejected.
     */
    public function test_rejects_readonly_field_type(): void {
        $this->resetAfterTest();
        [$course, $group1, , $teacher] = $this->make_course();

        $cfgenerator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgenerator->create_category([
            'component' => 'core_group',
            'area' => 'group',
            'itemid' => 0,
        ]);
        $cfgenerator->create_field([
            'categoryid' => $category->get('id'),
            'type' => 'textarea',
            'shortname' => 'groupnotes',
        ]);

        $this->setUser($teacher);
        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => 'groupnotes', 'value' => 'nope'],
        ]);
        $this->assert_invalid_parameter($response, 'Field not inline-editable: groupnotes');
        $this->assertNull($this->stored('groupnotes', (int) $group1->id));

        // Control: an inline-editable field of the same group is written.
        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_LOCATION, 'value' => 'Lab 2'],
        ]);
        $this->assertSame([], $this->refused($response));
        $this->assertSame('Lab 2', $this->stored(fields::SHORTNAME_LOCATION, (int) $group1->id)->charvalue);
    }

    /**
     * A number field whose value a provider computes is not inline-editable,
     * as core's group form does not let it be typed either.
     *
     * Core's number validation skips such a field, so a cell sent here would
     * otherwise overwrite the computed value. How such a field reaches a group
     * is shown in bulkedit_page_test.
     */
    public function test_rejects_a_provider_backed_number_field(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/groupdist/tests/fixtures/group_number_provider.php');
        $this->resetAfterTest();
        [$course, $group1, , $teacher] = $this->make_course();
        $this->create_group_field('number', 'computed', [
            'fieldtype' => \local_groupdist\fixtures\group_number_provider::class,
            'decimalplaces' => 0,
        ]);
        $this->create_group_field('number', 'typed', ['decimalplaces' => 0]);
        $this->setUser($teacher);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => 'computed', 'value' => '4'],
        ]);
        $this->assert_invalid_parameter($response, 'Field not inline-editable: computed');
        $this->assertNull($this->stored('computed', (int) $group1->id));

        // Control: a number field typed by hand is written.
        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => 'typed', 'value' => '4'],
        ]);
        $this->assertSame([], $this->refused($response));
        $this->assertSame(4.0, (float) $this->stored('typed', (int) $group1->id)->decvalue);
    }
}
