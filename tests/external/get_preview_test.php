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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Preview web service tests.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\external\get_preview
 */
final class get_preview_test extends \externallib_advanced_testcase {
    /**
     * Set up a course with groups and users; returns common WS args.
     *
     * @param int $groupcount Groups to create.
     * @param int $usercount Users to enrol.
     * @return array [course, groups, args].
     */
    private function make_course(int $groupcount, int $usercount): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $groups = [];
        for ($i = 1; $i <= $groupcount; $i++) {
            $groups[] = $generator->create_group([
                'courseid' => $course->id,
                'name' => sprintf('Group %02d', $i),
            ]);
        }
        for ($i = 0; $i < $usercount; $i++) {
            $generator->create_and_enrol($course);
        }
        $args = [
            'courseid' => (int) $course->id,
            'groupids' => implode(',', array_map(function (\stdClass $group): int {
                return (int) $group->id;
            }, $groups)),
            'seed' => 123,
        ];
        return [$course, $groups, $args];
    }

    /**
     * Call the function through the full external stack.
     *
     * @param array $args The request arguments.
     * @return array The cleaned response.
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_groupdist_get_preview', $args);
        $this->assertFalse($response['error'], $response['exception']->message ?? '');
        return external_api::clean_returnvalue(get_preview::execute_returns(), $response['data']);
    }

    /**
     * A teacher gets totals, paged groups and a stable fingerprint.
     */
    public function test_preview_pages_are_consistent(): void {
        $this->resetAfterTest();
        [$course, , $args] = $this->make_course(8, 20);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $page1 = $this->call($args + ['limitfrom' => 0, 'limitnum' => 5]);
        // 20 students plus the acting teacher: roleid 0 means any role.
        $this->assertSame(21, $page1['totals']['candidates']);
        $this->assertSame(8, $page1['totals']['groups']);
        $this->assertCount(5, $page1['groups']);
        $this->assertSame(8, $page1['total']);
        $this->assertFalse($page1['capped']);

        $page2 = $this->call($args + ['limitfrom' => 5, 'limitnum' => 5]);
        $this->assertCount(3, $page2['groups']);
        $this->assertSame($page1['fingerprint'], $page2['fingerprint']);

        // No overlap between pages.
        $ids1 = array_column($page1['groups'], 'id');
        $ids2 = array_column($page2['groups'], 'id');
        $this->assertSame([], array_intersect($ids1, $ids2));

        // Member samples are capped and marked new.
        foreach ($page1['groups'] as $group) {
            $this->assertLessThanOrEqual(get_preview::MEMBER_SAMPLE, count($group['members']));
        }
    }

    /**
     * The 25-group cap is enforced server-side.
     */
    public function test_preview_group_cap(): void {
        $this->resetAfterTest();
        [$course, , $args] = $this->make_course(30, 5);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->call($args + ['limitfrom' => 0, 'limitnum' => 5]);
        $this->assertTrue($result['capped']);
        $this->assertSame(30, $result['total']);
        $this->assertSame(get_preview::GROUP_CAP, $result['shownmax']);

        // A window beyond the cap comes back empty.
        $beyond = $this->call($args + ['limitfrom' => 25, 'limitnum' => 5]);
        $this->assertCount(0, $beyond['groups']);
    }

    /**
     * The capability gate is real: a student is rejected.
     */
    public function test_preview_requires_capability(): void {
        $this->resetAfterTest();
        [$course, , $args] = $this->make_course(2, 3);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_groupdist_get_preview', $args);
        $this->assertTrue($response['error']);
    }

    /**
     * A cohort the user cannot see is rejected — the WS must not become a
     * hidden-cohort membership oracle. A visible cohort passes (control).
     */
    public function test_preview_rejects_hidden_cohort(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        [$course, , $args] = $this->make_course(2, 3);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $hidden = $this->getDataGenerator()->create_cohort(['visible' => 0]);
        $visible = $this->getDataGenerator()->create_cohort(['visible' => 1]);

        $this->setUser($teacher);
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function(
            'local_groupdist_get_preview',
            $args + ['cohortid' => (int) $hidden->id]
        );
        $this->assertTrue($response['error']);

        $control = $this->call($args + ['cohortid' => (int) $visible->id]);
        $this->assertSame(0, $control['totals']['candidates']);
    }

    /**
     * An affinity field the user may not see is rejected server-side.
     */
    public function test_preview_rejects_disallowed_affinity_field(): void {
        $this->resetAfterTest();
        [$course, , $args] = $this->make_course(2, 3);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function(
            'local_groupdist_get_preview',
            $args + ['affinityfield' => 'profile_999999']
        );
        $this->assertTrue($response['error']);
    }
}
