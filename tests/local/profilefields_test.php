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
 * Affinity field enumeration tests — the visibility gates mirror core's
 * profile_field_base::is_visible() semantics.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\local\profilefields
 */
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
     * An ampersand is the only fixture that can reveal this: format_string's
     * escape flag rewrites nothing else, and it strips tags before escaping.
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
}
