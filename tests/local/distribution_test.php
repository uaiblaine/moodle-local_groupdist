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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Distribution builder tests: capacity math and fingerprint behaviour.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\local\distribution::class)]
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
            'affinityrules' => [['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]],
            'seed' => 5,
        ]);
        $before = distribution::build($options, $context);

        $victim = current($before->users);
        $update = (object) ['id' => $victim->id, 'city' => 'Elsewhere'];
        user_update_user($update, false);

        $after = distribution::build($options, $context);
        $this->assertNotSame($before->fingerprint, $after->fingerprint);
    }

    /**
     * With several rules, EVERY rule's values are fingerprinted: an edit to
     * the second rule's source shifts the print too.
     */
    public function test_fingerprint_covers_every_rules_values(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $group1, $group2] = $this->make_course(3);

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group1->id, (int) $group2->id],
            'affinityrules' => [
                ['source' => 'city', 'mode' => options::AFFINITY_TOGETHER],
                ['source' => 'department', 'mode' => options::AFFINITY_APART],
            ],
            'seed' => 5,
        ]);
        $before = distribution::build($options, $context);

        $victim = current($before->users);
        $update = (object) ['id' => $victim->id, 'department' => 'Elsewhere'];
        user_update_user($update, false);

        $after = distribution::build($options, $context);
        $this->assertNotSame($before->fingerprint, $after->fingerprint);
    }

    /**
     * Cohort membership churn between preview and apply is detected: adding a
     * member to a ruled cohort shifts the fingerprint even though the
     * candidate id set is unchanged.
     */
    public function test_fingerprint_covers_cohort_membership(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $group1, $group2] = $this->make_course(3);

        $cohort = $this->getDataGenerator()->create_cohort();
        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group1->id, (int) $group2->id],
            'affinityrules' => [['source' => 'cohort_' . $cohort->id, 'mode' => options::AFFINITY_APART]],
            'seed' => 5,
        ]);
        $before = distribution::build($options, $context);

        $victim = current($before->users);
        cohort_add_member($cohort->id, $victim->id);

        $after = distribution::build($options, $context);
        $this->assertNotSame($before->fingerprint, $after->fingerprint);
    }

    /**
     * Every way the preview can end up writing nothing reports a reason, and
     * a run that would write reports none.
     *
     * noop_reason() is keyed on memberships === 0 rather than on an empty
     * candidate list precisely so that the arms stay exhaustive: each case
     * below reached the teacher as a page of zeros with no sentence on it.
     *
     * Mutation: delete any one arm and its case falls through to the next,
     * returning the wrong reason.
     *
     * @return void
     */
    public function test_every_no_op_reports_its_reason(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $group1, $group2] = $this->make_course(4);
        $groupids = [(int) $group1->id, (int) $group2->id];

        $build = function (array $overrides) use ($course, $context, $groupids): distribution {
            return distribution::build(options::from_array($overrides + [
                'courseid' => $course->id,
                'groupids' => $groupids,
                'seed' => 5,
            ]), $context);
        };

        // Control: a run that writes has no reason at all. Without this the
        // assertions below pass on a method that returns a reason always.
        $writes = $build([]);
        $this->assertGreaterThan(0, $writes->totals()['memberships']);
        $this->assertSame('', $writes->noop_reason());
        $this->assertSame('', $writes->noop_message());

        // No candidates: a role nobody in the course holds.
        $managerrole = $this->getDataGenerator()->create_role(['shortname' => 'nobodyhere']);
        $nocandidates = $build(['roleid' => (int) $managerrole]);
        $this->assertSame(0, $nocandidates->totals()['candidates']);
        $this->assertSame(distribution::NOOP_NOCANDIDATES, $nocandidates->noop_reason());

        // No room: seats mode with every group already at its declared seats.
        \core_group\customfield\group_handler::create()->instance_form_save((object) [
            'id' => $group1->id,
            'customfield_' . fields::SHORTNAME_SEATS => 0,
        ]);
        \core_group\customfield\group_handler::create()->instance_form_save((object) [
            'id' => $group2->id,
            'customfield_' . fields::SHORTNAME_SEATS => 0,
        ]);
        $noroom = $build(['useseats' => 1]);
        $this->assertGreaterThan(0, $noroom->totals()['candidates']);
        $this->assertGreaterThan(0, $noroom->totals()['unassigned']);
        $this->assertSame(distribution::NOOP_NOROOM, $noroom->noop_reason());

        // No groups: the selection is deleted while the preview is open. The
        // web service re-intersects on every call, so this really is reachable.
        groups_delete_group($group1->id);
        groups_delete_group($group2->id);
        $nogroups = distribution::build(options::from_array([
            'courseid' => $course->id,
            'groupids' => [],
            'seed' => 5,
        ]), $context);
        $this->assertSame(0, $nogroups->totals()['groups']);
        $this->assertSame(distribution::NOOP_NOGROUPS, $nogroups->noop_reason());
    }

    /**
     * Everyone already sitting in the group the plan chose is its own reason,
     * not a silent zero and not "no room".
     *
     * Reachable only with the keep-grouped filter off, which is what leaves a
     * current member in the candidate list; a keep-together rule then routes
     * the cluster at the group they are already in. Pinned at the allocator by
     * allocator_test::test_cluster_members_already_in_the_target_are_skipped_not_unassigned.
     *
     * @return void
     */
    public function test_all_placed_is_its_own_reason(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        foreach ([0, 1] as $ignored) {
            // A shared, NON-EMPTY together value is what routes them through
            // the cluster placement; empty values fall to the singleton pool
            // instead and come back as unassigned, which is a different reason.
            $user = $generator->create_and_enrol($course, 'student', ['city' => 'Fortaleza']);
            $generator->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);
        }

        $distribution = distribution::build(options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'ignoregrouped' => 0,
            'affinityrules' => [['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]],
            'seed' => 5,
        ]), $context);

        $totals = $distribution->totals();
        $this->assertSame(2, $totals['candidates']);
        $this->assertSame(0, $totals['memberships']);
        $this->assertSame(0, $totals['unassigned']);
        $this->assertSame(distribution::NOOP_ALLPLACED, $distribution->noop_reason());
        $this->assertNotSame('', $distribution->noop_message());
    }

    /**
     * The empty-roster message names the keep-grouped filter when it is on,
     * because a second run over the same groups is the commonest way to get
     * here — and stays silent about it when it is off, so the hint never
     * claims a cause that cannot apply.
     *
     * Mutation: delete the ignoregrouped branch in noop_message().
     *
     * @return void
     */
    public function test_the_empty_roster_message_names_the_keep_grouped_filter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $context, $group1] = $this->make_course(2);
        $role = $this->getDataGenerator()->create_role(['shortname' => 'nobodyhere']);

        $build = function (int $ignoregrouped) use ($course, $context, $group1, $role): distribution {
            return distribution::build(options::from_array([
                'courseid' => $course->id,
                'groupids' => [(int) $group1->id],
                'roleid' => (int) $role,
                'ignoregrouped' => $ignoregrouped,
                'seed' => 5,
            ]), $context);
        };

        $hint = get_string('noophintignoregrouped', 'local_groupdist');
        $on = $build(1);
        $this->assertSame(distribution::NOOP_NOCANDIDATES, $on->noop_reason());
        $this->assertStringContainsString($hint, $on->noop_message());

        // Control: same reason, filter off, no hint.
        $off = $build(0);
        $this->assertSame(distribution::NOOP_NOCANDIDATES, $off->noop_reason());
        $this->assertStringNotContainsString($hint, $off->noop_message());
    }
}
