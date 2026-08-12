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
        // Untouched: group2's location stays unset (the partial save is partial).
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
        $this->assertTrue($response['error']);
    }

    /**
     * The capability gate is real: a student is rejected.
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();
        [$course, $group1] = $this->make_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '5'],
        ]);
        $this->assertTrue($response['error']);
    }

    /**
     * Groups of another course and non-numeric seats are rejected.
     */
    public function test_rejects_bad_input(): void {
        $this->resetAfterTest();
        [$course, $group1, , $teacher] = $this->make_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $foreign = $this->getDataGenerator()->create_group(['courseid' => $othercourse->id]);
        $this->setUser($teacher);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $foreign->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => '5'],
        ]);
        $this->assertTrue($response['error']);

        $response = $this->call((int) $course->id, [
            ['groupid' => (int) $group1->id, 'shortname' => fields::SHORTNAME_SEATS, 'value' => 'abc'],
        ]);
        $this->assertTrue($response['error']);
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
        $this->assertTrue($response['error']);
    }
}
