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
 * Cohort search web service tests.
 *
 * PHPUnit metadata is written as docblock tags, never attributes: Moodle 4.5
 * runs PHPUnit 9, which reads only the tags, and its moodle-cs reports every
 * test of a class without a covers tag.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\external\search_cohorts
 */
final class search_cohorts_test extends \externallib_advanced_testcase {
    /**
     * Call the function through the full external stack.
     *
     * @param array $args The request arguments.
     * @return array The cleaned response.
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_groupdist_search_cohorts', $args);
        $this->assertFalse($response['error'], $response['exception']->message ?? '');
        return external_api::clean_returnvalue(search_cohorts::execute_returns(), $response['data']);
    }

    /**
     * Matches follow the query and hidden cohorts never appear.
     */
    public function test_search_filters_and_visibility(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $generator->create_cohort(['name' => 'Alpha Mentors', 'visible' => 1]);
        $generator->create_cohort(['name' => 'Beta Squad', 'visible' => 1]);
        $hidden = $generator->create_cohort(['name' => 'Alpha Hidden', 'visible' => 0]);

        $this->setUser($teacher);
        $result = $this->call(['courseid' => (int) $course->id, 'query' => 'Alpha']);

        $labels = array_column($result['cohorts'], 'label');
        $this->assertContains('Alpha Mentors', $labels);
        $this->assertNotContains('Beta Squad', $labels);
        $this->assertNotContains('Alpha Hidden', $labels);
        $this->assertNotContains('cohort_' . $hidden->id, array_column($result['cohorts'], 'value'));
    }

    /**
     * The returned label is plain: rules.js writes each match with
     * option.textContent, which does not interpret entities, so an escaped
     * name would be shown to the teacher literally.
     *
     * @return void
     */
    public function test_the_returned_label_is_not_pre_escaped(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_cohort([
            'contextid' => \core\context\system::instance()->id,
            'name' => 'Ciencias & Letras',
        ]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->call(['courseid' => (int) $course->id, 'query' => 'Ciencias']);

        $labels = array_column($result['cohorts'], 'label');
        $this->assertSame(['Ciencias & Letras'], $labels);
    }

    /**
     * With formatstringstriptags off a cohort name still arrives plain, as the
     * PARAM_TEXT label rules.js writes with textContent
     * ({@see \local_groupdist\local\plaintext}).
     *
     * @return void
     */
    public function test_the_label_is_plain_with_formatstringstriptags_off(): void {
        $this->resetAfterTest();
        set_config('formatstringstriptags', 0);
        $course = $this->getDataGenerator()->create_course();
        foreach (['Turma A & B', 'Turma <3 anos'] as $name) {
            $this->getDataGenerator()->create_cohort([
                'contextid' => \core\context\system::instance()->id,
                'name' => $name,
            ]);
        }
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $labels = array_column($this->call(['courseid' => (int) $course->id, 'query' => 'Turma'])['cohorts'], 'label');

        sort($labels);
        $this->assertSame(['Turma ', 'Turma A & B'], $labels);
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
        $generator->create_cohort(['contextid' => \core\context\system::instance()->id, 'name' => 'Alpha Mentors']);
        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $args = ['courseid' => (int) $course->id, 'query' => 'Alpha'];

        $this->setUser($teacher);
        $this->assertSame(['Alpha Mentors'], array_column($this->call($args)['cohorts'], 'label'));

        $this->setUser($student);
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_groupdist_search_cohorts', $args);
        $this->assertTrue($response['error']);
        $this->assertSame('nopermissions', $response['exception']->errorcode);
    }
}
