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
 * Ruleset value object tests.
 *
 * Pure: the guardrail maximum is a parameter, so no DB is ever touched.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\local\ruleset::class)]
final class ruleset_test extends \basic_testcase {
    /**
     * A valid list round-trips and preserves priority order.
     */
    public function test_valid_rules_round_trip(): void {
        $rules = [
            ['source' => 'department', 'mode' => options::AFFINITY_TOGETHER],
            ['source' => 'profile_7', 'mode' => options::AFFINITY_APART],
            ['source' => 'cohort_31', 'mode' => options::AFFINITY_APART],
        ];
        $ruleset = ruleset::from_array($rules);

        $this->assertSame(3, $ruleset->count());
        $this->assertFalse($ruleset->is_empty());
        $this->assertSame($rules, $ruleset->get_rules());
        $this->assertSame($rules, $ruleset->to_array());
        $this->assertSame(['source' => 'department', 'mode' => options::AFFINITY_TOGETHER], $ruleset->first());
    }

    /**
     * The empty ruleset is valid and reports itself as such.
     */
    public function test_empty_ruleset(): void {
        $ruleset = ruleset::from_array([]);
        $this->assertTrue($ruleset->is_empty());
        $this->assertSame(0, $ruleset->count());
        $this->assertNull($ruleset->first());
        $this->assertSame([], $ruleset->to_array());
    }

    /**
     * Entries may arrive as stdClass (json-decoded adhoc task customdata).
     */
    public function test_accepts_stdclass_entries(): void {
        $entry = new \stdClass();
        $entry->source = 'city';
        $entry->mode = options::AFFINITY_TOGETHER;

        $ruleset = ruleset::from_array([$entry]);
        $this->assertSame([['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]], $ruleset->get_rules());
    }

    /**
     * The versioned JSON envelope round-trips.
     */
    public function test_json_round_trip(): void {
        $ruleset = ruleset::from_array([['source' => 'city', 'mode' => options::AFFINITY_APART]]);
        $json = $ruleset->to_json();

        $this->assertStringContainsString('"v":' . ruleset::VERSION, $json);
        $this->assertSame($ruleset->get_rules(), ruleset::from_json($json)->get_rules());
    }

    /**
     * An unknown envelope version is rejected.
     */
    public function test_json_rejects_unknown_version(): void {
        $this->expectException(\moodle_exception::class);
        ruleset::from_json('{"v":99,"rules":[]}');
    }

    /**
     * An invalid mode is rejected.
     */
    public function test_rejects_bad_mode(): void {
        $this->expectException(\moodle_exception::class);
        ruleset::from_array([['source' => 'city', 'mode' => 'sideways']]);
    }

    /**
     * A source outside the grammar (native whitelist, profile_<id>, cohort_<id>) is rejected.
     */
    public function test_rejects_bad_source(): void {
        $this->expectException(\moodle_exception::class);
        ruleset::from_array([['source' => 'email', 'mode' => options::AFFINITY_TOGETHER]]);
    }

    /**
     * The same source may not appear twice.
     */
    public function test_rejects_duplicate_source(): void {
        $this->expectException(\moodle_exception::class);
        ruleset::from_array([
            ['source' => 'city', 'mode' => options::AFFINITY_TOGETHER],
            ['source' => 'city', 'mode' => options::AFFINITY_APART],
        ]);
    }

    /**
     * Structural keys are rejected: modes must never appear inside a tree node,
     * so an entry carrying an operator is unrepresentable by design.
     */
    public function test_rejects_operator_keys(): void {
        $this->expectException(\moodle_exception::class);
        ruleset::from_array([['source' => 'city', 'mode' => options::AFFINITY_TOGETHER, 'op' => '&']]);
    }

    /**
     * An entry missing a required key is rejected.
     */
    public function test_rejects_incomplete_entry(): void {
        $this->expectException(\moodle_exception::class);
        ruleset::from_array([['source' => 'city']]);
    }

    /**
     * The guardrail maximum is enforced.
     */
    public function test_rejects_too_many_rules(): void {
        $this->expectException(\moodle_exception::class);
        ruleset::from_array([
            ['source' => 'city', 'mode' => options::AFFINITY_TOGETHER],
            ['source' => 'department', 'mode' => options::AFFINITY_TOGETHER],
            ['source' => 'country', 'mode' => options::AFFINITY_APART],
        ], 2);
    }

    /**
     * Source classification helpers.
     */
    public function test_source_helpers(): void {
        $this->assertSame(ruleset::KIND_NATIVE, ruleset::source_kind('city'));
        $this->assertSame(ruleset::KIND_PROFILE, ruleset::source_kind('profile_12'));
        $this->assertSame(ruleset::KIND_COHORT, ruleset::source_kind('cohort_7'));
        $this->assertSame('', ruleset::source_kind('profile_'));
        $this->assertSame('', ruleset::source_kind(''));

        $this->assertSame(12, ruleset::source_profile_fieldid('profile_12'));
        $this->assertSame(0, ruleset::source_profile_fieldid('cohort_12'));
        $this->assertSame(7, ruleset::source_cohortid('cohort_7'));
        $this->assertSame(0, ruleset::source_cohortid('city'));
    }

    /**
     * A course group is a source kind of its own, and the two id-shaped
     * encodings never bleed into each other.
     *
     * The grouping cases are the point: 'grouping_7' is a plausible future
     * key, and an unanchored group pattern would classify it as a group with
     * id 7 — which would then be authorized against, and read from, an
     * entirely unrelated group.
     */
    public function test_group_source_helpers(): void {
        $this->assertSame(ruleset::KIND_GROUP, ruleset::source_kind('group_9'));
        $this->assertSame(9, ruleset::source_groupid('group_9'));

        $this->assertSame('', ruleset::source_kind('grouping_7'));
        $this->assertSame(0, ruleset::source_groupid('grouping_7'));
        $this->assertSame('', ruleset::source_kind('group_'));
        $this->assertSame('', ruleset::source_kind('group_7x'));
        $this->assertSame('', ruleset::source_kind('Group_7'));
        $this->assertSame(0, ruleset::source_groupid('cohort_9'));
        $this->assertSame(0, ruleset::source_cohortid('group_9'));
        $this->assertSame(0, ruleset::source_profile_fieldid('group_9'));

        // A group source is accepted by the grammar gate every entry point uses.
        $ruleset = ruleset::from_array([['source' => 'group_9', 'mode' => options::AFFINITY_APART]]);
        $this->assertSame([['source' => 'group_9', 'mode' => options::AFFINITY_APART]], $ruleset->to_array());
    }

    /**
     * Membership sources are the ones whose stored value is a bare '1', so
     * every display path has to substitute the rule label instead.
     */
    public function test_membership_sources(): void {
        $this->assertTrue(ruleset::is_membership_source('cohort_7'));
        $this->assertTrue(ruleset::is_membership_source('group_9'));
        $this->assertFalse(ruleset::is_membership_source('city'));
        $this->assertFalse(ruleset::is_membership_source('profile_12'));
        $this->assertFalse(ruleset::is_membership_source('grouping_7'));
    }
}
