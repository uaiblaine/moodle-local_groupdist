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
 * Distribution builder tests: capacity math and fingerprint behaviour.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\local\distribution
 */
final class distribution_test extends \advanced_testcase {
    /**
     * Set up a course with two groups, seats on one, and enrolled users.
     *
     * @param int $usercount Users to enrol.
     * @return array [course, context, group1 (seats 3), group2 (no seats)].
     */
    private function make_course(int $usercount): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group1 = $generator->create_group(['courseid' => $course->id, 'name' => 'A group']);
        $group2 = $generator->create_group(['courseid' => $course->id, 'name' => 'B group']);
        for ($i = 0; $i < $usercount; $i++) {
            $generator->create_and_enrol($course);
        }

        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();
        \core_group\customfield\group_handler::create()->instance_form_save((object) [
            'id' => $group1->id,
            'customfield_' . fields::SHORTNAME_SEATS => 3,
        ]);

        return [$course, $context, $group1, $group2];
    }

    /**
     * Seats mode: capacity = seats + overbook - current; groups without a value
     * are unlimited and reported.
     */
    public function test_capacity_math_and_noseats_warning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $group1, $group2] = $this->make_course(10);

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group1->id, (int) $group2->id],
            'useseats' => 1,
            'overbook' => 1,
            'seed' => 5,
        ]);
        $distribution = distribution::build($options, $context);

        // Group1 (seats 3, overbook 1) takes at most 4; group2 absorbs the rest.
        $this->assertLessThanOrEqual(4, count($distribution->allocation->assignments[(int) $group1->id]));
        $this->assertSame(10, $distribution->allocation->count_memberships());

        $types = array_column($distribution->warnings, 'type');
        $this->assertContains(distribution::WARNING_NOSEATS, $types);

        $totals = $distribution->totals();
        $this->assertSame(10, $totals['candidates']);
        $this->assertSame(2, $totals['groups']);
        $this->assertSame(3, $totals['seatstotal']);
    }

    /**
     * Group ids not belonging to the course are dropped.
     */
    public function test_foreign_groupids_dropped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $group1] = $this->make_course(2);
        $othercourse = $this->getDataGenerator()->create_course();
        $foreign = $this->getDataGenerator()->create_group(['courseid' => $othercourse->id]);

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group1->id, (int) $foreign->id],
            'seed' => 5,
        ]);
        $distribution = distribution::build($options, $context);

        $this->assertCount(1, $distribution->groups);
        $this->assertSame((int) $group1->id, $distribution->groups[0]['id']);
    }

    /**
     * The fingerprint is stable across identical rebuilds and shifts on any
     * enrolment or membership change — the staleness guard the apply relies on.
     */
    public function test_fingerprint_stability_and_sensitivity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $group1, $group2] = $this->make_course(4);

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group1->id, (int) $group2->id],
            'seed' => 5,
        ]);

        $first = distribution::build($options, $context);
        $second = distribution::build($options, $context);
        $this->assertSame($first->fingerprint, $second->fingerprint);
        $this->assertSame($first->allocation->assignments, $second->allocation->assignments);

        // An enrolment change shifts it.
        $extra = $this->getDataGenerator()->create_and_enrol($course);
        $third = distribution::build($options, $context);
        $this->assertNotSame($first->fingerprint, $third->fingerprint);

        // A concurrent membership change shifts it too (current counts are covered).
        $this->getDataGenerator()->create_group_member(['groupid' => $group1->id, 'userid' => $extra->id]);
        $fourth = distribution::build($options, $context);
        $this->assertNotSame($third->fingerprint, $fourth->fingerprint);

        // A rename shifts it: sort keys feed every allocation order, including
        // the shuffle's input permutation.
        $extra->lastname = 'Zzzzz';
        user_update_user($extra, false);
        $fifth = distribution::build($options, $context);
        $this->assertNotSame($fourth->fingerprint, $fifth->fingerprint);
    }

    /**
     * An affinity value edit between preview and apply shifts the fingerprint —
     * the bucketing depends on it even though the candidate id set is unchanged.
     */
    public function test_fingerprint_covers_affinity_values(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $group1, $group2] = $this->make_course(3);

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group1->id, (int) $group2->id],
            'affinityfield' => 'city',
            'affinitymode' => options::AFFINITY_TOGETHER,
            'seed' => 5,
        ]);
        $before = distribution::build($options, $context);

        $victim = current($before->users);
        $update = (object) ['id' => $victim->id, 'city' => 'Elsewhere'];
        user_update_user($update, false);

        $after = distribution::build($options, $context);
        $this->assertNotSame($before->fingerprint, $after->fingerprint);
    }
}
