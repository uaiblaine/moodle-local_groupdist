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

        $runid = runlog::create($distribution, (int) get_admin()->id, $context);

        $sink = $this->redirectEvents();
        $summary = applier::apply($distribution, null, $runid);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame(6, $summary['added']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame([], $summary['failedpairs']);
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
        // The event points at the audit snapshot row.
        $this->assertSame($runid, (int) $runevents[0]->objectid);
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

        $first = distribution::build($options, $context);
        applier::apply($first, null, runlog::create($first, (int) get_admin()->id, $context));
        $second = distribution::build($options, $context);
        applier::apply($second, null, runlog::create($second, (int) get_admin()->id, $context));

        $this->assertSame(1, $DB->count_records('groups_members', ['groupid' => $group->id]));
    }

    /**
     * An already-present membership is counted as written even when the target
     * group is hidden from the person running the distribution.
     *
     * The replay path has to tell a duplicate key apart from a genuine write
     * failure, and groups_is_member() cannot answer that: it is
     * visibility-filtered, so for a GROUPS_VISIBILITY_OWN group it reports
     * "not a member" for a row that plainly exists. The same blindness inside
     * core's own groups_add_member() guard is what forces the duplicate here.
     */
    public function test_apply_counts_existing_membership_in_hidden_group(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group([
            'courseid' => $course->id,
            'visibility' => GROUPS_VISIBILITY_OWN,
        ]);
        for ($i = 0; $i < 3; $i++) {
            $generator->create_and_enrol($course);
        }

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'seed' => 42,
        ]);
        $distribution = distribution::build($options, $context);
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);

        /* Someone adds one of the planned members by hand between the preview
           and the apply — the race the replay path exists for. Inserted
           directly so the plan built above still names this pair. */
        $planned = $distribution->allocation->assignments[(int) $group->id];
        $preexisting = (int) reset($planned);
        $member = (object) [
            'groupid' => (int) $group->id,
            'userid' => $preexisting,
            'timeadded' => time(),
            'component' => '',
            'itemid' => 0,
        ];
        $DB->insert_record('groups_members', $member);

        // The distributor cannot see hidden groups: a custom role or, as here, an explicit prevent.
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/course:viewhiddengroups', CAP_PREVENT, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($teacher);

        /* Preconditions. Without them this test passes by doing nothing: if the
           distributor could see the group, core's own guard would report the
           member, no duplicate key would be raised and the replay path — the
           only place the probe lives — would never be entered. */
        $this->assertFalse(has_capability('moodle/course:viewhiddengroups', $context));
        $this->assertTrue(
            $DB->record_exists('groups_members', ['groupid' => $group->id, 'userid' => $preexisting])
        );
        $this->assertFalse(groups_is_member($group->id, $preexisting));

        $summary = applier::apply($distribution, null, $runid);

        // The row that already existed is a success, not a failure.
        $this->assertSame(3, $summary['added']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame([], $summary['failedpairs']);
        $this->assertSame(3, $DB->count_records('groups_members', ['groupid' => $group->id]));

        /* Only the two genuinely new rows carry this run's stamp: the
           pre-existing one was recognised, not rewritten, which is what
           proves the duplicate-key replay is the branch under test. */
        $this->assertSame(2, $DB->count_records('groups_members', ['component' => 'local_groupdist', 'itemid' => 42]));
    }
}
