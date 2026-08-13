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
 * Options value object tests around the affinity ruleset integration.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\local\options
 */
final class options_test extends \advanced_testcase {
    /**
     * The canonical array shape round-trips including the ruleset and the
     * future-enrolments flag.
     */
    public function test_to_array_round_trip(): void {
        $options = options::from_array([
            'courseid' => 7,
            'groupids' => [3, 5],
            'allocateby' => options::ALLOCATE_LASTNAME,
            'includefuture' => 1,
            'affinityrules' => [['source' => 'city', 'mode' => options::AFFINITY_APART]],
            'seed' => 99,
        ]);

        $exported = $options->to_array();
        $this->assertSame([['source' => 'city', 'mode' => options::AFFINITY_APART]], $exported['affinityrules']);
        $this->assertSame(1, $exported['includefuture']);

        $reimported = options::from_array($exported);
        $this->assertSame($options->to_array(), $reimported->to_array());
    }

    /**
     * The first rule feeds the engine helpers; no rule means the balanced path.
     */
    public function test_affinity_helpers(): void {
        $none = options::from_array(['seed' => 1]);
        $this->assertSame('', $none->get_affinity_source());
        $this->assertSame(options::AFFINITY_TOGETHER, $none->get_affinity_mode());
        $this->assertFalse($none->is_native_affinity());
        $this->assertSame(0, $none->get_custom_affinity_fieldid());

        $custom = options::from_array([
            'seed' => 1,
            'affinityrules' => [['source' => 'profile_12', 'mode' => options::AFFINITY_APART]],
        ]);
        $this->assertSame('profile_12', $custom->get_affinity_source());
        $this->assertSame(options::AFFINITY_APART, $custom->get_affinity_mode());
        $this->assertFalse($custom->is_native_affinity());
        $this->assertSame(12, $custom->get_custom_affinity_fieldid());
    }

    /**
     * Transitional engine limit: more than one rule is rejected, never
     * silently truncated. Drops out when the multi-rule allocator lands.
     */
    public function test_rejects_multiple_rules_for_now(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        options::from_array([
            'seed' => 1,
            'affinityrules' => [
                ['source' => 'city', 'mode' => options::AFFINITY_TOGETHER],
                ['source' => 'department', 'mode' => options::AFFINITY_APART],
            ],
        ]);
    }

    /**
     * The flattened POST transport (parallel scalar arrays) pairs back up by
     * shared index.
     */
    public function test_rules_from_post(): void {
        $this->resetAfterTest();
        $_POST['affinityrulesources'] = ['0' => 'city'];
        $_POST['affinityrulemodes'] = ['0' => options::AFFINITY_APART];

        $this->assertSame(
            [['source' => 'city', 'mode' => options::AFFINITY_APART]],
            options::rules_from_post()
        );

        unset($_POST['affinityrulesources'], $_POST['affinityrulemodes']);
        $this->assertSame([], options::rules_from_post());
    }
}
