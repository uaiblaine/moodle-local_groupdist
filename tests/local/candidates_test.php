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
 * Candidate query tests.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\local\candidates
 */
final class candidates_test extends \advanced_testcase {
    /**
     * Base options for a course.
     *
     * @param int $courseid The course id.
     * @param array $overrides Option overrides.
     * @return options The options.
     */
    private function make_options(int $courseid, array $overrides = []): options {
        return options::from_array($overrides + [
            'courseid' => $courseid,
            'groupids' => [],
            'allocateby' => options::ALLOCATE_LASTNAME,
            'seed' => 1,
        ]);
    }

    /**
     * Only enrolled users come back; the role filter narrows further.
     */
    public function test_role_filter(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $generator->create_user();

        $this->setAdminUser();
        $all = candidates::fetch($this->make_options($course->id), $context);
        $ids = array_map('intval', array_keys($all));
        $this->assertContains((int) $student->id, $ids);
        $this->assertContains((int) $teacher->id, $ids);
        $this->assertCount(2, $ids);

        $studentrole = current(get_archetype_roles('student'));
        $onlystudents = candidates::fetch(
            $this->make_options($course->id, ['roleid' => (int) $studentrole->id]),
            $context
        );
        $this->assertSame([(int) $student->id], array_map('intval', array_keys($onlystudents)));
    }

    /**
     * Cohort filter keeps cohort members only.
     */
    public function test_cohort_filter(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $inside = $generator->create_and_enrol($course);
        $generator->create_and_enrol($course);
        $cohort = $generator->create_cohort();
        cohort_add_member($cohort->id, $inside->id);

        $this->setAdminUser();
        $result = candidates::fetch($this->make_options($course->id, ['cohortid' => (int) $cohort->id]), $context);
        $this->assertSame([(int) $inside->id], array_map('intval', array_keys($result)));
    }

    /**
     * Suspended enrolments are excluded when onlyactive is on — with a control
     * proving the filter (not an empty course) removed the user.
     */
    public function test_only_active_enrolments(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $active = $generator->create_and_enrol($course);
        $suspended = $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);

        $this->setAdminUser();

        // Control: with the filter off the suspended user IS a candidate.
        $without = candidates::fetch($this->make_options($course->id, ['onlyactive' => 0]), $context);
        $this->assertContains((int) $suspended->id, array_map('intval', array_keys($without)));

        $with = candidates::fetch($this->make_options($course->id, ['onlyactive' => 1]), $context);
        $ids = array_map('intval', array_keys($with));
        $this->assertContains((int) $active->id, $ids);
        $this->assertNotContains((int) $suspended->id, $ids);
    }

    /**
     * Without the viewsuspendedusers capability, onlyactive is forced on.
     */
    public function test_only_active_forced_without_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $suspended = $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        unassign_capability('moodle/course:viewsuspendedusers', 3, $context->id);
        assign_capability('moodle/course:viewsuspendedusers', CAP_PROHIBIT, 3, $context->id, true);

        $this->setUser($teacher);
        $result = candidates::fetch($this->make_options($course->id, ['onlyactive' => 0]), $context);
        $this->assertNotContains((int) $suspended->id, array_map('intval', array_keys($result)));
    }

    /**
     * The future-start option includes active-but-not-started enrolments while
     * suspended enrolments stay out — with controls on both sides.
     */
    public function test_includefuture_enrolments(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $active = $generator->create_and_enrol($course);
        $future = $generator->create_and_enrol($course, 'student', null, 'manual', time() + WEEKSECS);
        $suspended = $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);

        $this->setAdminUser();

        // Control: only-active alone excludes the future-start enrolment.
        $without = candidates::fetch($this->make_options($course->id, ['onlyactive' => 1]), $context);
        $ids = array_map('intval', array_keys($without));
        $this->assertContains((int) $active->id, $ids);
        $this->assertNotContains((int) $future->id, $ids);

        $with = candidates::fetch(
            $this->make_options($course->id, ['onlyactive' => 1, 'includefuture' => 1]),
            $context
        );
        $ids = array_map('intval', array_keys($with));
        $this->assertContains((int) $active->id, $ids);
        $this->assertContains((int) $future->id, $ids);
        // Future-start is not suspended: the suspended user stays out.
        $this->assertNotContains((int) $suspended->id, $ids);
    }

    /**
     * Without viewsuspendedusers the future-start option is forced off, like
     * the only-active filter itself.
     */
    public function test_includefuture_forced_off_without_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $future = $generator->create_and_enrol($course, 'student', null, 'manual', time() + WEEKSECS);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        unassign_capability('moodle/course:viewsuspendedusers', 3, $context->id);
        assign_capability('moodle/course:viewsuspendedusers', CAP_PROHIBIT, 3, $context->id, true);

        $this->setUser($teacher);
        $result = candidates::fetch(
            $this->make_options($course->id, ['onlyactive' => 1, 'includefuture' => 1]),
            $context
        );
        $this->assertNotContains((int) $future->id, array_map('intval', array_keys($result)));
    }

    /**
     * A cohort rule yields the binary membership column: '1' for members,
     * empty for everyone else.
     */
    public function test_cohort_rule_column(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $member = $generator->create_and_enrol($course);
        $outsider = $generator->create_and_enrol($course);
        $cohort = $generator->create_cohort();
        cohort_add_member($cohort->id, $member->id);

        $this->setAdminUser();
        $result = candidates::fetch($this->make_options($course->id, [
            'affinityrules' => [['source' => 'cohort_' . $cohort->id, 'mode' => options::AFFINITY_APART]],
        ]), $context);

        $this->assertSame('1', $result[(int) $member->id]->affinity0);
        $this->assertNull($result[(int) $outsider->id]->affinity0);
    }

    /**
     * "Ignore grouped" excludes members of the SELECTED groups only.
     */
    public function test_ignoregrouped_scoped_to_selected_groups(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $selected = $generator->create_group(['courseid' => $course->id]);
        $othergroup = $generator->create_group(['courseid' => $course->id]);
        $inselected = $generator->create_and_enrol($course);
        $inother = $generator->create_and_enrol($course);
        $free = $generator->create_and_enrol($course);
        $generator->create_group_member(['groupid' => $selected->id, 'userid' => $inselected->id]);
        $generator->create_group_member(['groupid' => $othergroup->id, 'userid' => $inother->id]);

        $this->setAdminUser();
        $result = candidates::fetch($this->make_options($course->id, [
            'groupids' => [(int) $selected->id],
            'ignoregrouped' => 1,
        ]), $context);
        $ids = array_map('intval', array_keys($result));

        $this->assertNotContains((int) $inselected->id, $ids);
        // Deliberate deviation from autogroup: membership in a NON-selected
        // group does not exclude a user.
        $this->assertContains((int) $inother->id, $ids);
        $this->assertContains((int) $free->id, $ids);
    }

    /**
     * Native and custom affinity columns ride along with the candidates.
     */
    public function test_affinity_columns(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $user = $generator->create_and_enrol($course);
        $user->city = 'Fortaleza';
        user_update_user($user, false);

        $field = $generator->create_custom_profile_field([
            'shortname' => 'campus',
            'name' => 'Campus',
            'datatype' => 'text',
        ]);
        profile_save_data((object) ['id' => $user->id, 'profile_field_campus' => 'Sobral']);

        $this->setAdminUser();

        $bycity = candidates::fetch(
            $this->make_options($course->id, [
                'affinityrules' => [['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]],
            ]),
            $context
        );
        $this->assertSame('Fortaleza', $bycity[(int) $user->id]->affinity0);

        $bycustom = candidates::fetch(
            $this->make_options($course->id, [
                'affinityrules' => [['source' => 'profile_' . $field->id, 'mode' => options::AFFINITY_TOGETHER]],
            ]),
            $context
        );
        $this->assertSame('Sobral', $bycustom[(int) $user->id]->affinity0);
    }

    /**
     * Memberships stamped by the run itself do not disqualify a candidate —
     * that is what lets an interrupted apply resume the identical plan.
     */
    public function test_ignoregrouped_excludes_own_run_writes(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        $user = $generator->create_and_enrol($course);

        $this->setAdminUser();
        $options = $this->make_options($course->id, [
            'groupids' => [(int) $group->id],
            'ignoregrouped' => 1,
            'seed' => 77,
        ]);

        // A membership written by this very run (itemid = seed) is invisible...
        groups_add_member($group->id, $user->id, 'local_groupdist', 77);
        $result = candidates::fetch($options, $context);
        $this->assertContains((int) $user->id, array_map('intval', array_keys($result)));

        // ...while a foreign membership excludes the user as usual (control).
        $options->seed = 78;
        $result = candidates::fetch($options, $context);
        $this->assertNotContains((int) $user->id, array_map('intval', array_keys($result)));
    }
}
