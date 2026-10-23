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

namespace local_groupdist;

use local_groupdist\local\fields;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the public seats reader other plugins call.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\seats::class)]
final class seats_test extends \advanced_testcase {
    /**
     * Store a Seats value on a group through core's handler.
     *
     * @param int $groupid Group id.
     * @param int $seats Seats to declare.
     * @return void
     */
    private function set_seats(int $groupid, int $seats): void {
        \core_group\customfield\group_handler::create()->instance_form_save((object) [
            'id' => $groupid,
            'customfield_' . fields::SHORTNAME_SEATS => $seats,
        ]);
    }

    /**
     * Each group gets its declared seats, or null when it declares none, and its
     * member count; a zero is a real capacity, not "no limit".
     *
     * Changes that must make it fail: reading the seats of the wrong group, or
     * turning a declared 0 into null.
     *
     * @return void
     */
    public function test_seats_and_members_per_group(): void {
        $this->resetAfterTest();
        // The core handler saves only fields the current user can edit.
        $this->setAdminUser();
        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $full = $generator->create_group(['courseid' => $course->id]);
        $closed = $generator->create_group(['courseid' => $course->id]);
        $open = $generator->create_group(['courseid' => $course->id]);
        $this->set_seats((int) $full->id, 2);
        $this->set_seats((int) $closed->id, 0);

        foreach ([1, 2] as $i) {
            $user = $generator->create_and_enrol($course);
            groups_add_member($full->id, $user->id);
        }

        $result = seats::for_groups([$full->id, $closed->id, $open->id]);

        $this->assertSame([(int) $full->id, (int) $closed->id, (int) $open->id], array_keys($result));
        $this->assertSame(2, $result[(int) $full->id]->seats);
        $this->assertSame(2, $result[(int) $full->id]->members);
        $this->assertSame(0, $result[(int) $closed->id]->seats);
        $this->assertSame(0, $result[(int) $closed->id]->members);
        $this->assertNull($result[(int) $open->id]->seats);
    }

    /**
     * Every member takes a seat: a teacher, and a membership another component
     * stamped, count exactly like a student's - the bulk edit page's count.
     *
     * Changes that must make it fail: counting students only, or dropping
     * component-stamped rows (fields::get_member_counts() with a seed).
     *
     * @return void
     */
    public function test_every_member_counts_whatever_role_or_component(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);

        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $stamped = $generator->create_and_enrol($course, 'student');
        groups_add_member($group->id, $student->id);
        groups_add_member($group->id, $teacher->id);
        groups_add_member($group->id, $stamped->id, 'local_groupdist', 42);

        $this->assertSame(3, seats::for_groups([$group->id])[(int) $group->id]->members);
    }

    /**
     * With the Seats field gone, every group reads as declaring no limit, and
     * the members are still counted.
     *
     * Changes that must make it fail: throwing, or reporting 0 seats (which a
     * consumer would read as a full group) when the field does not exist.
     *
     * @return void
     */
    public function test_no_seats_field_means_no_limit(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        fields::reset_field_cache();
        fields::delete_provisioned_fields();
        fields::reset_field_cache();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);
        $user = $generator->create_and_enrol($course);
        groups_add_member($group->id, $user->id);

        $result = seats::for_groups([$group->id]);

        $this->assertNull($result[(int) $group->id]->seats);
        $this->assertSame(1, $result[(int) $group->id]->members);
    }

    /**
     * Ids arrive as the database returns them (strings), sometimes twice, and
     * an empty list costs no query.
     *
     * Changes that must make it fail: keys that keep the string spelling, a
     * duplicate key, or get_in_or_equal() reached with an empty list (it throws).
     *
     * @return void
     */
    public function test_ids_are_normalised_and_an_empty_list_is_empty(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);

        $result = seats::for_groups([(string) $group->id, (int) $group->id]);

        $this->assertSame([(int) $group->id], array_keys($result));
        $this->assertSame([], seats::for_groups([]));
    }

    /**
     * A group id that does not exist reads as an empty group with no limit,
     * so a consumer holding a stale id never blocks on it.
     *
     * @return void
     */
    public function test_an_unknown_group_has_no_limit_and_no_members(): void {
        $this->resetAfterTest();

        $result = seats::for_groups([987654]);

        $this->assertNull($result[987654]->seats);
        $this->assertSame(0, $result[987654]->members);
    }
}
