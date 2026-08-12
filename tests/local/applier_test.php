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
 * Applier tests: memberships written through core, component stamp, event.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\local\applier
 */
final class applier_test extends \advanced_testcase {
    /**
     * Applying writes every planned membership, stamps the component and
     * fires the run event.
     */
    public function test_apply_writes_memberships(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);
        for ($i = 0; $i < 6; $i++) {
            $generator->create_and_enrol($course);
        }

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group1->id, (int) $group2->id],
            'seed' => 99,
        ]);
        $distribution = distribution::build($options, $context);

        $sink = $this->redirectEvents();
        $summary = applier::apply($distribution);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame(6, $summary['added']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(3, $DB->count_records('groups_members', ['groupid' => $group1->id]));
        $this->assertSame(3, $DB->count_records('groups_members', ['groupid' => $group2->id]));

        // Every row carries the plugin's component stamp and the seed as itemid.
        $this->assertSame(
            6,
            $DB->count_records('groups_members', ['component' => 'local_groupdist', 'itemid' => 99])
        );

        // Core fired one member_added per membership plus our run event.
        $runevents = array_values(array_filter($events, function (\core\event\base $event): bool {
            return $event instanceof \local_groupdist\event\distribution_applied;
        }));
        $this->assertCount(1, $runevents);
        $this->assertSame(6, $runevents[0]->other['memberships']);
        $memberevents = array_filter($events, function (\core\event\base $event): bool {
            return $event instanceof \core\event\group_member_added;
        });
        $this->assertCount(6, $memberevents);
    }

    /**
     * Re-applying the same plan is a no-op: core reports existing members as
     * successes and no duplicate rows appear.
     */
    public function test_apply_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_and_enrol($course);

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'seed' => 7,
            'ignoregrouped' => 0,
        ]);

        applier::apply(distribution::build($options, $context));
        applier::apply(distribution::build($options, $context));

        $this->assertSame(1, $DB->count_records('groups_members', ['groupid' => $group->id]));
    }
}
