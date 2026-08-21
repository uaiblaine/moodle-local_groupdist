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
 * Affinity field enumeration tests — the visibility gates mirror core's
 * profile_field_base::is_visible() semantics.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\local\profilefields::class)]
final class profilefields_test extends \advanced_testcase {
    /**
     * Create one custom profile field per visibility level.
     *
     * @return array Map of visibility constant => field record.
     */
    private function make_fields(): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $generator = $this->getDataGenerator();
        $fields = [];
        $levels = [
            PROFILE_VISIBLE_ALL => 'visall',
            PROFILE_VISIBLE_TEACHERS => 'visteachers',
            PROFILE_VISIBLE_PRIVATE => 'visprivate',
            PROFILE_VISIBLE_NONE => 'visnone',
        ];
        foreach ($levels as $visible => $shortname) {
            $fields[$visible] = $generator->create_custom_profile_field([
                'shortname' => $shortname,
                'name' => 'Field ' . $shortname,
                'datatype' => 'text',
                'visible' => $visible,
            ]);
        }
        return $fields;
    }

    /**
     * Field and cohort labels reach callers unescaped. Every consumer escapes
     * for itself: the rule builder prints them through Mustache double
     * stashes, rules.js writes search results with textContent, and the
     * preview payload lands in double stashes too. Escaping here showed a
     * cohort named "Ciencias & Letras" as "Ciencias &amp; Letras" on the first
     * screen of the flow.
     *
     * A bare ampersand is a valid fixture; a tag-shaped one would not be,
     * since format_string strips tags identically in both escape modes.
     *
     * @return void
     */
    public function test_labels_are_not_pre_escaped(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $context = \core\context\course::instance($course->id);

        $field = $this->getDataGenerator()->create_custom_profile_field([
            'shortname' => 'ampfield',
            'name' => 'Turma A & B',
            'datatype' => 'text',
            'visible' => PROFILE_VISIBLE_ALL,
        ]);
        $cohort = $this->getDataGenerator()->create_cohort([
            'contextid' => \core\context\system::instance()->id,
            'name' => 'Ciencias & Letras',
        ]);

        $fields = profilefields::get_fields($context);
        $this->assertSame('Turma A & B', $fields['profile_' . $field->id]);
        $this->assertSame('Turma A & B', profilefields::get_label('profile_' . $field->id, $context));
        $this->assertStringContainsString(
            'Ciencias & Letras',
            profilefields::get_label('cohort_' . $cohort->id, $context)
        );
        $this->assertStringNotContainsString(
            'Ciencias &amp; Letras',
            profilefields::get_label('cohort_' . $cohort->id, $context)
        );
    }

    /**
     * An editing teacher sees ALL and TEACHERS fields, never private/hidden ones.
     */
    public function test_editingteacher_sees_teacher_visible_fields_only(): void {
        $this->resetAfterTest();
        $fields = $this->make_fields();
        $course = $this->getDataGenerator()->create_course();
        $context = \core\context\course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->setUser($teacher);
        $available = profilefields::get_fields($context);

        $this->assertArrayHasKey('profile_' . $fields[PROFILE_VISIBLE_ALL]->id, $available);
        $this->assertArrayHasKey('profile_' . $fields[PROFILE_VISIBLE_TEACHERS]->id, $available);
        $this->assertArrayNotHasKey('profile_' . $fields[PROFILE_VISIBLE_PRIVATE]->id, $available);
        $this->assertArrayNotHasKey('profile_' . $fields[PROFILE_VISIBLE_NONE]->id, $available);

        // The is_allowed() gate must agree with the listing (WS/apply use it).
        $this->assertTrue(profilefields::is_allowed('profile_' . $fields[PROFILE_VISIBLE_ALL]->id, $context));
        $this->assertFalse(profilefields::is_allowed('profile_' . $fields[PROFILE_VISIBLE_PRIVATE]->id, $context));
    }

    /**
     * With moodle/user:viewalldetails (manager-level) every field is offered —
     * the control proving the teacher-side exclusion is the capability, not
     * a broken listing.
     */
    public function test_viewalldetails_sees_every_field(): void {
        $this->resetAfterTest();
        $fields = $this->make_fields();
        $course = $this->getDataGenerator()->create_course();
        $context = \core\context\course::instance($course->id);

        $this->setAdminUser();
        $available = profilefields::get_fields($context);

        foreach ($fields as $field) {
            $this->assertArrayHasKey('profile_' . $field->id, $available);
        }
    }

    /**
     * Native fields are always present; cohorts are never enumerated here.
     */
    public function test_native_fields_always_offered(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \core\context\course::instance($course->id);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $cohort = $this->getDataGenerator()->create_cohort(['visible' => 1]);

        $this->setUser($student);
        $available = profilefields::get_fields($context);

        foreach (options::NATIVE_AFFINITY_FIELDS as $field) {
            $this->assertArrayHasKey($field, $available);
        }
        // Cohorts are picked through the bounded menu or the search, and are
        // authorized per rule via cohort_get_cohort() — never listed here.
        $this->assertArrayNotHasKey('cohort_' . $cohort->id, $available);
        $this->assertTrue(profilefields::is_allowed('cohort_' . $cohort->id, $context));
    }

    /**
     * get_fields() lists FIELDS. Course groups have their own listing helper,
     * so a group source must not appear here — the two are separate because
     * they answer to different visibility rules and different pickers.
     */
    public function test_groups_are_not_listed_among_the_fields(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Lab team']);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->setUser($teacher);

        $this->assertArrayNotHasKey('group_' . $group->id, profilefields::get_fields($context));
        // The control: it really is usable as a source, so the assertion above
        // is about enumeration and not about permission.
        $this->assertTrue(profilefields::is_allowed('group_' . $group->id, $context));
    }

    /**
     * A group source is authorized against this course's groups only.
     */
    public function test_group_source_is_course_scoped(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $mine = $generator->create_group(['courseid' => $course->id, 'name' => 'Mine']);
        $theirs = $generator->create_group(['courseid' => $other->id, 'name' => 'Theirs']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $this->setUser($teacher);

        $this->assertTrue(profilefields::is_allowed('group_' . $mine->id, $context));
        /* Another course's group must not resolve. groups_get_group() would
           accept it — it checks neither the course nor the visibility — which
           is exactly why the helper here is groups_get_all_groups(). */
        $this->assertFalse(profilefields::is_allowed('group_' . $theirs->id, $context));
        $this->assertFalse(profilefields::is_allowed('group_' . ($theirs->id + 1000), $context));
        $this->assertSame('', profilefields::get_label('group_' . $theirs->id, $context));
    }

    /**
     * Which visibility levels may be a rule source, and for whom.
     *
     * A rule source exposes who is in the group — the preview paints a badge
     * on each member — so the set is "groups whose full membership the actor
     * can already read". groups_get_all_groups() gives all of that except the
     * OWN case, where core shows a member only their own row; that one is
     * subtracted on top, and only for an actor without viewhiddengroups.
     */
    public function test_group_source_visibility_matrix(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $levels = [
            'all' => GROUPS_VISIBILITY_ALL,
            'members' => GROUPS_VISIBILITY_MEMBERS,
            'own' => GROUPS_VISIBILITY_OWN,
            'none' => GROUPS_VISIBILITY_NONE,
        ];
        $groups = [];
        foreach ($levels as $name => $visibility) {
            $groups[$name] = $generator->create_group([
                'courseid' => $course->id,
                'name' => 'Group ' . $name,
                'visibility' => $visibility,
            ]);
        }

        // A student who is a member of every group, and holds no group capability.
        $student = $generator->create_and_enrol($course, 'student');
        foreach ($groups as $group) {
            $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        }

        $this->setUser($student);
        $offered = profilefields::get_source_groups($context);

        $this->assertArrayHasKey((int) $groups['all']->id, $offered);
        // A member of a MEMBERS group may read the whole member list, so it is
        // a sound source.
        $this->assertArrayHasKey((int) $groups['members']->id, $offered);
        // OWN is the one case groups_get_all_groups() returns but this must not:
        // core shows a member only their own row there.
        $this->assertArrayNotHasKey((int) $groups['own']->id, $offered);
        $this->assertArrayNotHasKey((int) $groups['none']->id, $offered);
        $this->assertFalse(profilefields::is_allowed('group_' . $groups['own']->id, $context));

        /* The control, and the reason this is a capability question rather
           than a broken listing: an actor holding viewhiddengroups sees every
           group of the course, OWN and NONE included. If this half failed, the
           exclusion above would be proving nothing about the capability. */
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $offered = profilefields::get_source_groups($context);
        foreach ($groups as $name => $group) {
            $this->assertArrayHasKey((int) $group->id, $offered, $name . ' should be offered to a teacher');
        }
        $this->assertTrue(profilefields::is_allowed('group_' . $groups['own']->id, $context));
    }

    /**
     * The visibility rule holds on a COLD cache, which is the case
     * groups_get_all_groups() cannot be trusted for.
     *
     * core_group\visibility::can_view_all_groups() re-reads its cache entry
     * after warming it and then discards the value, so a missing entry
     * evaluates `false > 0` and reports "this course has no hidden groups".
     * groups_get_all_groups() then takes its unfiltered MUC shortcut and hands
     * back every group of the course. Anything that merely subtracts OWN from
     * that result leaks a NONE-visibility group — its name to the picker, and
     * its whole membership to the preview — for one call after each cache
     * purge, which every plugin install or upgrade performs.
     *
     * create_group() warms that entry, so the matrix test above only ever sees
     * the warm path; this one purges it first. Delete the MEMBERS/OWN/NONE
     * arms in get_source_groups() and this goes red while that one stays green.
     */
    public function test_group_source_visibility_holds_on_a_cold_cache(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $open = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'Open group',
            'visibility' => GROUPS_VISIBILITY_ALL,
        ]);
        $secret = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'Secret group',
            'visibility' => GROUPS_VISIBILITY_NONE,
        ]);
        $notmine = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'Members only',
            'visibility' => GROUPS_VISIBILITY_MEMBERS,
        ]);
        $student = $generator->create_and_enrol($course, 'student');

        $this->setUser($student);
        \cache_helper::purge_by_definition('core', 'coursehiddengroups');

        $offered = profilefields::get_source_groups($context);
        // The control: the readable group is still offered, so a pass here is
        // the visibility arms and not an empty listing.
        $this->assertArrayHasKey((int) $open->id, $offered);
        $this->assertArrayNotHasKey((int) $secret->id, $offered);
        $this->assertArrayNotHasKey((int) $notmine->id, $offered);
        $this->assertFalse(profilefields::is_allowed('group_' . $secret->id, $context));
        $this->assertFalse(profilefields::is_allowed('group_' . $notmine->id, $context));
    }

    /**
     * A group name reaches its consumers plain, like every other source label,
     * and the snapshot label carries the "Group: " prefix.
     *
     * The fixture is a bare ampersand on purpose: format_string() rewrites
     * & and any surviving < or >, and nothing else — a tag-shaped fixture is
     * stripped identically in both escape modes and would prove nothing.
     */
    public function test_group_labels_are_not_pre_escaped(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id, 'name' => 'Turma A & B']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $this->setUser($teacher);

        $this->assertSame('Turma A & B', profilefields::get_source_groups($context)[(int) $group->id]);
        $label = profilefields::get_label('group_' . $group->id, $context);
        $this->assertStringContainsString('Turma A & B', $label);
        $this->assertStringNotContainsString('Turma A &amp; B', $label);
        $this->assertStringContainsString('Group:', $label);
    }
}
