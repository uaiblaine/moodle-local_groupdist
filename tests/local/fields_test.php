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

namespace local_groupdist\local;

/**
 * Tests for the group custom field provisioning and bulk readers.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\local\fields
 */
final class fields_test extends \advanced_testcase {
    /**
     * Provisioning creates both fields exactly once, no matter how often it runs.
     */
    public function test_ensure_fields_exist_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();

        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();

        $this->assertSame(1, $DB->count_records('customfield_field', ['shortname' => fields::SHORTNAME_SEATS]));
        $this->assertSame(1, $DB->count_records('customfield_field', ['shortname' => fields::SHORTNAME_LOCATION]));
        $this->assertNotNull(fields::get_seats_field());
        $this->assertNotNull(fields::get_location_field());
        $this->assertSame('number', fields::get_seats_field()->get('type'));
        $this->assertSame('text', fields::get_location_field()->get('type'));
    }

    /**
     * Provisioning heals a deleted category on the next run.
     */
    public function test_ensure_fields_exist_recreates_after_deletion(): void {
        $this->resetAfterTest();

        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();
        fields::delete_provisioned_fields();

        $this->assertNull(fields::get_seats_field());

        fields::ensure_fields_exist();
        fields::reset_field_cache();
        $this->assertNotNull(fields::get_seats_field());
        $this->assertNotNull(fields::get_location_field());
    }

    /**
     * Bulk value reader returns seats and location per group in one shape.
     */
    public function test_get_group_values(): void {
        $this->resetAfterTest();
        // The core handler saves only fields the current user can edit.
        $this->setAdminUser();
        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);

        // Store a seats value for group1 only, via the core handler.
        $handler = \core_group\customfield\group_handler::create();
        $handler->instance_form_save((object) [
            'id' => $group1->id,
            'customfield_' . fields::SHORTNAME_SEATS => 12,
            'customfield_' . fields::SHORTNAME_LOCATION => 'Room 101',
        ]);

        $values = fields::get_group_values([(int) $group1->id, (int) $group2->id]);

        $this->assertSame(12, $values[(int) $group1->id]->seats);
        $this->assertSame('Room 101', $values[(int) $group1->id]->location);
        $this->assertNull($values[(int) $group2->id]->seats);
        $this->assertNull($values[(int) $group2->id]->location);
    }

    /**
     * Member counts come back for every requested group, zeros included.
     */
    public function test_get_member_counts(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);
        $user1 = $generator->create_and_enrol($course);
        $user2 = $generator->create_and_enrol($course);
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $user1->id]);
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $user2->id]);

        $counts = fields::get_member_counts([(int) $group1->id, (int) $group2->id]);

        $this->assertSame(2, $counts[(int) $group1->id]);
        $this->assertSame(0, $counts[(int) $group2->id]);
    }

    /**
     * Member counts can hide the plugin's own writes for one seed — the
     * resume-after-interruption contract.
     */
    public function test_get_member_counts_excluding_own_seed(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);
        $user1 = $generator->create_and_enrol($course);
        $user2 = $generator->create_and_enrol($course);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $user1->id]);
        groups_add_member($group->id, $user2->id, 'local_groupdist', 42);

        $plain = fields::get_member_counts([(int) $group->id]);
        $this->assertSame(2, $plain[(int) $group->id]);

        $excluding = fields::get_member_counts([(int) $group->id], 42);
        $this->assertSame(1, $excluding[(int) $group->id]);

        // A different seed's writes are not excluded (control).
        $other = fields::get_member_counts([(int) $group->id], 43);
        $this->assertSame(2, $other[(int) $group->id]);
    }
}
