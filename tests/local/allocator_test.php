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
 * Allocator unit tests (pure logic, no database).
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\local\allocator
 */
final class allocator_test extends \basic_testcase {
    /**
     * Build an options object for allocator input.
     *
     * @param array $overrides Option overrides.
     * @return options The options.
     */
    private function make_options(array $overrides = []): options {
        return options::from_array($overrides + [
            'courseid' => 1,
            'groupids' => [],
            'allocateby' => options::ALLOCATE_LASTNAME,
            'seed' => 42,
        ]);
    }

    /**
     * Group state entry helper.
     *
     * @param int $id Group id.
     * @param int|null $capacity Remaining capacity (null = unlimited).
     * @param int $current Current member count.
     * @param array $existing Existing member ids.
     * @return array The group entry.
     */
    private function make_group(int $id, ?int $capacity, int $current = 0, array $existing = []): array {
        return [
            'id' => $id,
            'capacity' => $capacity,
            'current' => $current,
            'existing' => array_fill_keys($existing, true),
        ];
    }

    /**
     * Balanced fill spreads users evenly and keeps alphabetical runs contiguous.
     */
    public function test_balanced_contiguous_chunks(): void {
        $groups = [$this->make_group(1, null), $this->make_group(2, null), $this->make_group(3, null)];
        $result = allocator::allocate([1, 2, 3, 4, 5, 6, 7], [], $groups, $this->make_options());

        // Same shape as core autogroup: floor(7/3) each, remainder to the
        // earliest groups — and alphabetical runs stay contiguous.
        $this->assertSame([1, 2, 3], $result->assignments[1]);
        $this->assertSame([4, 5], $result->assignments[2]);
        $this->assertSame([6, 7], $result->assignments[3]);
        $this->assertSame([], $result->unassigned);
        $this->assertSame([], $result->warnings);
    }

    /**
     * Balanced fill equalises FINAL sizes when groups start unequal.
     */
    public function test_balanced_counts_existing_members(): void {
        // Group 1 already has 4 members; group 2 none. 6 users: 1 + 5 split.
        $groups = [$this->make_group(1, null, 4), $this->make_group(2, null, 0)];
        $result = allocator::allocate([1, 2, 3, 4, 5, 6], [], $groups, $this->make_options());

        $this->assertCount(1, $result->assignments[1]);
        $this->assertCount(5, $result->assignments[2]);
    }

    /**
     * Capacity caps assignments and reports the unplaceable remainder.
     */
    public function test_capacity_overflow_reports_unassigned(): void {
        $groups = [$this->make_group(1, 2), $this->make_group(2, 1)];
        $result = allocator::allocate([1, 2, 3, 4, 5], [], $groups, $this->make_options());

        $this->assertCount(2, $result->assignments[1]);
        $this->assertCount(1, $result->assignments[2]);
        $this->assertCount(2, $result->unassigned);
        $this->assertSame(allocator::WARNING_UNASSIGNED, $result->warnings[0]['type']);
        $this->assertSame(2, $result->warnings[0]['count']);
    }

    /**
     * Random order is deterministic for a seed and differs between seeds.
     */
    public function test_random_deterministic_by_seed(): void {
        $groups = [$this->make_group(1, null), $this->make_group(2, null)];
        $userids = range(1, 40);
        $options = $this->make_options(['allocateby' => options::ALLOCATE_RANDOM, 'seed' => 7]);

        $first = allocator::allocate($userids, [], $groups, $options);
        $second = allocator::allocate($userids, [], $groups, $options);
        $this->assertSame($first->assignments, $second->assignments);

        $other = allocator::allocate(
            $userids,
            [],
            $groups,
            $this->make_options(['allocateby' => options::ALLOCATE_RANDOM, 'seed' => 8])
        );
        $this->assertNotSame($first->assignments, $other->assignments);
    }

    /**
     * Keep-together puts every holder of a value in one group.
     */
    public function test_together_keeps_values_in_one_group(): void {
        $groups = [$this->make_group(1, null), $this->make_group(2, null)];
        $affinity = [1 => 'A', 2 => 'A', 3 => 'A', 4 => 'B', 5 => 'B', 6 => 'B'];
        $options = $this->make_options([
            'affinityfield' => 'city',
            'affinitymode' => options::AFFINITY_TOGETHER,
        ]);
        $result = allocator::allocate([1, 2, 3, 4, 5, 6], $affinity, $groups, $options);

        foreach (['A' => [1, 2, 3], 'B' => [4, 5, 6]] as $members) {
            $ingroup1 = array_intersect($result->assignments[1], $members);
            $ingroup2 = array_intersect($result->assignments[2], $members);
            $this->assertTrue(count($ingroup1) === 0 || count($ingroup2) === 0);
        }
        $this->assertSame([], $result->warnings);
    }

    /**
     * A bucket larger than any group's capacity splits with a warning.
     */
    public function test_together_splits_oversized_bucket(): void {
        $groups = [$this->make_group(1, 2), $this->make_group(2, 2)];
        $affinity = [1 => 'A', 2 => 'A', 3 => 'A'];
        $options = $this->make_options([
            'affinityfield' => 'city',
            'affinitymode' => options::AFFINITY_TOGETHER,
        ]);
        $result = allocator::allocate([1, 2, 3], $affinity, $groups, $options);

        $this->assertSame(3, count($result->assignments[1]) + count($result->assignments[2]));
        $types = array_column($result->warnings, 'type');
        $this->assertContains(allocator::WARNING_SPLIT, $types);
    }

    /**
     * Users without a value are placed and reported.
     */
    public function test_together_reports_users_without_value(): void {
        $groups = [$this->make_group(1, null), $this->make_group(2, null)];
        $affinity = [1 => 'A', 2 => '', 3 => null];
        $options = $this->make_options([
            'affinityfield' => 'city',
            'affinitymode' => options::AFFINITY_TOGETHER,
        ]);
        $result = allocator::allocate([1, 2, 3], $affinity, $groups, $options);

        $this->assertSame(3, $result->count_memberships());
        $novalue = array_values(array_filter($result->warnings, function (array $warning): bool {
            return $warning['type'] === allocator::WARNING_NOVALUE;
        }));
        $this->assertCount(1, $novalue);
        $this->assertSame(2, $novalue[0]['count']);
    }

    /**
     * Keep-apart spreads holders of one value over distinct groups.
     */
    public function test_apart_spreads_values(): void {
        $groups = [$this->make_group(1, null), $this->make_group(2, null), $this->make_group(3, null)];
        $affinity = [1 => 'A', 2 => 'A', 3 => 'A', 4 => 'B', 5 => 'B', 6 => 'C'];
        $options = $this->make_options([
            'affinityfield' => 'city',
            'affinitymode' => options::AFFINITY_APART,
        ]);
        $result = allocator::allocate([1, 2, 3, 4, 5, 6], $affinity, $groups, $options);

        // Each of A's three holders sits in a different group.
        foreach ($result->assignments as $userids) {
            $holders = array_intersect($userids, [1, 2, 3]);
            $this->assertLessThanOrEqual(1, count($holders));
        }
        $this->assertSame([], $result->warnings);
    }

    /**
     * Pigeonhole: more holders than groups is infeasible and reported per value.
     */
    public function test_apart_pigeonhole_reports_infeasible(): void {
        $groups = [$this->make_group(1, null), $this->make_group(2, null)];
        $affinity = [1 => 'A', 2 => 'A', 3 => 'A'];
        $options = $this->make_options([
            'affinityfield' => 'city',
            'affinitymode' => options::AFFINITY_APART,
        ]);
        $result = allocator::allocate([1, 2, 3], $affinity, $groups, $options);

        $this->assertSame(3, $result->count_memberships());
        $apart = array_values(array_filter($result->warnings, function (array $warning): bool {
            return $warning['type'] === allocator::WARNING_APART;
        }));
        $this->assertCount(1, $apart);
        $this->assertSame('A', $apart[0]['value']);
        $this->assertSame(1, $apart[0]['count']);
    }

    /**
     * Existing members are never re-assigned to their own group.
     */
    public function test_existing_member_not_reassigned_in_affinity_modes(): void {
        $groups = [
            $this->make_group(1, null, 1, [1]),
            $this->make_group(2, null),
        ];
        $affinity = [1 => 'A', 2 => 'A'];
        $options = $this->make_options([
            'affinityfield' => 'city',
            'affinitymode' => options::AFFINITY_TOGETHER,
        ]);
        $result = allocator::allocate([1, 2], $affinity, $groups, $options);

        // User 1 already sits in group 1 (the emptiest-by-final choice is group 2,
        // but bucket packing prefers max remaining; either way user 1 must not be
        // duplicated into a group they already belong to).
        $this->assertNotContains(1, $result->assignments[1]);
    }

    /**
     * Balanced mode must also honour existing memberships: with ignore-grouped
     * off, nobody is handed back to a group they already belong to.
     */
    public function test_balanced_respects_existing_memberships(): void {
        $groups = [
            $this->make_group(1, null, 2, [1, 2]),
            $this->make_group(2, null, 2, [3, 4]),
        ];
        $result = allocator::allocate([1, 2, 3, 4], [], $groups, $this->make_options());

        $this->assertNotContains(1, $result->assignments[1]);
        $this->assertNotContains(2, $result->assignments[1]);
        $this->assertNotContains(3, $result->assignments[2]);
        $this->assertNotContains(4, $result->assignments[2]);
        // Everyone still gets placed — in the other group.
        $this->assertSame(4, $result->count_memberships());
    }

    /**
     * Empty inputs produce an empty allocation.
     */
    public function test_empty_inputs(): void {
        $result = allocator::allocate([], [], [$this->make_group(1, null)], $this->make_options());
        $this->assertSame(0, $result->count_memberships());

        $result = allocator::allocate([1], [], [], $this->make_options());
        $this->assertSame([], $result->assignments);
        $this->assertSame(0, $result->count_memberships());
    }
}
