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
use local_groupdist\local\profilefields;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Course group search web service tests.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\external\search_groups::class)]
final class search_groups_test extends \externallib_advanced_testcase {
    /**
     * Call the function through the full external stack.
     *
     * @param array $args The request arguments.
     * @return array The cleaned response.
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_groupdist_search_groups', $args);
        $this->assertFalse($response['error'], $response['exception']->message ?? '');
        return external_api::clean_returnvalue(search_groups::execute_returns(), $response['data']);
    }

    /**
     * Matches follow the query and stay inside the course.
     */
    public function test_search_filters_and_is_course_scoped(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $generator->create_group(['courseid' => $course->id, 'name' => 'Alpha Lab']);
        $generator->create_group(['courseid' => $course->id, 'name' => 'Beta Squad']);
        $elsewhere = $generator->create_group(['courseid' => $other->id, 'name' => 'Alpha Elsewhere']);

        $this->setUser($teacher);
        /* Lower-cased and padded on purpose: the match folds case through
           core_text and trims the query, and a needle that already matches
           byte-for-byte would exercise neither. */
        $result = $this->call(['courseid' => (int) $course->id, 'query' => '  alPHa  ']);

        $labels = array_column($result['groups'], 'label');
        $this->assertContains('Alpha Lab', $labels);
        $this->assertNotContains('Beta Squad', $labels);
        $this->assertNotContains('Alpha Elsewhere', $labels);
        $this->assertNotContains('group_' . $elsewhere->id, array_column($result['groups'], 'value'));
    }

    /**
     * Every match this offers is one the submit-side validator accepts.
     *
     * Both sides read profilefields::get_source_groups() rather than the search
     * running a query of its own, so the offer set and the validator cannot
     * drift apart.
     */
    public function test_every_offered_group_is_accepted_by_the_validator(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        foreach (['Lab A', 'Lab B', 'Seminar'] as $name) {
            $generator->create_group(['courseid' => $course->id, 'name' => $name]);
        }
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $this->setUser($teacher);
        $result = $this->call(['courseid' => (int) $course->id, 'query' => '']);

        $this->assertCount(3, $result['groups']);
        foreach ($result['groups'] as $group) {
            $this->assertTrue(
                profilefields::is_allowed($group['value'], $context),
                $group['value'] . ' was offered but the validator rejects it.'
            );
        }
    }

    /**
     * An OWN-visibility group is never offered to a member who cannot see
     * hidden groups — the picker must not advertise what the validator denies.
     */
    public function test_own_visibility_group_is_not_offered(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');

        $own = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'Own Lab',
            'visibility' => GROUPS_VISIBILITY_OWN,
        ]);
        $generator->create_group_member(['groupid' => $own->id, 'userid' => $student->id]);
        $generator->create_group(['courseid' => $course->id, 'name' => 'Own Public']);

        // The control: a teacher holds viewhiddengroups, so both are offered.
        $this->setUser($teacher);
        $labels = array_column($this->call(['courseid' => (int) $course->id, 'query' => 'Own'])['groups'], 'label');
        $this->assertContains('Own Lab', $labels);
        $this->assertContains('Own Public', $labels);

        // A role without it: the OWN group is gone, the ALL one stays.
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'local/groupdist:distribute',
            CAP_ALLOW,
            $roleid,
            \core\context\course::instance($course->id)->id
        );
        role_assign($roleid, $student->id, \core\context\course::instance($course->id)->id);

        $this->setUser($student);
        $labels = array_column($this->call(['courseid' => (int) $course->id, 'query' => 'Own'])['groups'], 'label');
        $this->assertNotContains('Own Lab', $labels);
        $this->assertContains('Own Public', $labels);
    }

    /**
     * The returned label is plain: rules.js writes each match with
     * option.textContent, which does not interpret entities, so an escaped
     * name would be shown to the teacher literally.
     */
    public function test_the_returned_label_is_not_pre_escaped(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_group(['courseid' => $course->id, 'name' => 'Ciencias & Letras']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $this->setUser($teacher);
        $result = $this->call(['courseid' => (int) $course->id, 'query' => 'Ciencias']);

        $this->assertSame(['Ciencias & Letras'], array_column($result['groups'], 'label'));
    }

    /**
     * The capability gate is real: a student is rejected, and a teacher making
     * the same call is answered (control).
     *
     * The error code pins the refusal to the require_capability() call, not to
     * any error the call could raise.
     */
    public function test_search_requires_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_group(['courseid' => $course->id, 'name' => 'Alpha Lab']);
        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $args = ['courseid' => (int) $course->id, 'query' => ''];

        $this->setUser($teacher);
        $this->assertSame(['Alpha Lab'], array_column($this->call($args)['groups'], 'label'));

        $this->setUser($student);
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_groupdist_search_groups', $args);
        $this->assertTrue($response['error']);
        $this->assertSame('nopermissions', $response['exception']->errorcode);
    }

    /**
     * With formatstringstriptags off a group name still arrives plain, as the
     * PARAM_TEXT label rules.js writes with textContent
     * ({@see \local_groupdist\local\plaintext}).
     */
    public function test_the_label_is_plain_with_formatstringstriptags_off(): void {
        $this->resetAfterTest();
        set_config('formatstringstriptags', 0);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_group(['courseid' => $course->id, 'name' => 'Turma A & B']);
        $generator->create_group(['courseid' => $course->id, 'name' => 'Turma <3 anos']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $this->setUser($teacher);
        $labels = array_column($this->call(['courseid' => (int) $course->id, 'query' => 'Turma'])['groups'], 'label');

        sort($labels);
        $this->assertSame(['Turma ', 'Turma A & B'], $labels);
    }
}
