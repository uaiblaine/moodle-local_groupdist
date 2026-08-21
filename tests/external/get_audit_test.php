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
use local_groupdist\local\auditreader;
use local_groupdist\local\distribution;
use local_groupdist\local\options;
use local_groupdist\local\runlog;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Audit report web service tests: windows, search, gates and the allowlist.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\external\get_audit_sections::class)]
#[CoversClass(\local_groupdist\external\get_audit_members::class)]
#[CoversClass(\local_groupdist\external\audit_ws::class)]
final class get_audit_test extends \externallib_advanced_testcase {
    /**
     * Seed a run.
     *
     * @param int $usercount How many students take part.
     * @param string $city The city value every student holds.
     * @param array $rules Affinity rules, in the options array shape.
     * @return array [course, teacher, run record].
     */
    private function seed(int $usercount = 3, string $city = 'Recife', array $rules = []): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id, 'name' => 'Turma A']);
        for ($i = 0; $i < $usercount; $i++) {
            $user = $generator->create_and_enrol($course);
            $user->city = $city;
            user_update_user($user, false);
        }
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $this->setAdminUser();
        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'affinityrules' => $rules,
            'roleid' => (int) current(get_archetype_roles('student'))->id,
            'seed' => 7,
        ]);
        $runid = runlog::create(distribution::build($options, $context), (int) get_admin()->id, $context);
        return [$course, $teacher, $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST)];
    }

    /**
     * Call the sections function through the full external stack.
     *
     * @param array $args The request arguments.
     * @return array The cleaned response.
     */
    private function sections(array $args): array {
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_groupdist_get_audit_sections', $args);
        $this->assertFalse($response['error'], $response['exception']->message ?? '');
        return external_api::clean_returnvalue(get_audit_sections::execute_returns(), $response['data']);
    }

    /**
     * Call the members function through the full external stack.
     *
     * @param array $args The request arguments.
     * @return array The cleaned response.
     */
    private function members(array $args): array {
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_groupdist_get_audit_members', $args);
        $this->assertFalse($response['error'], $response['exception']->message ?? '');
        return external_api::clean_returnvalue(get_audit_members::execute_returns(), $response['data']);
    }

    /**
     * A teacher gets the run's sections, with the paging bar and the counts.
     */
    public function test_sections_payload(): void {
        $this->resetAfterTest();
        [$course, $teacher, $run] = $this->seed(3);

        $this->setUser($teacher);
        $result = $this->sections([
            'runid' => (int) $run->id,
            'courseid' => (int) $course->id,
            'userquery' => '',
            'groupquery' => '',
            'page' => 0,
        ]);

        $this->assertSame(1, $result['total']);
        $this->assertSame(3, $result['matchingmembers']);
        $this->assertCount(1, $result['sections']);
        $this->assertSame('Turma A', $result['sections'][0]['name']);
        $this->assertCount(3, $result['sections'][0]['members']);
        // One page of sections: core renders no bar, and that is the answer
        // the client swaps in — the key must still be there to swap.
        $this->assertArrayHasKey('pagingbar', $result);
    }

    /**
     * A rule value carrying markup survives the return allowlist.
     *
     * PARAM_TEXT rejects a value it would have to clean, so an unsanitised
     * explanation sentence makes clean_returnvalue throw — the page would
     * render fine and then die on the first search keystroke.
     */
    public function test_markup_in_a_rule_value_survives_the_allowlist(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $run] = $this->seed(
            2,
            'Recife',
            [['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]]
        );

        /* The snapshot is where such a value actually comes from: core cleans
           the native user columns on save, but a textarea custom profile field
           legitimately stores markup and the run recorded whatever it held. */
        $DB->execute(
            'UPDATE {local_groupdist_run_user} SET valuesjson = :values WHERE runid = :runid',
            ['values' => json_encode(['<p>Recife</p>']), 'runid' => (int) $run->id]
        );
        $stored = $DB->get_field_sql(
            'SELECT valuesjson FROM {local_groupdist_run_user} WHERE runid = ?',
            [(int) $run->id],
            IGNORE_MULTIPLE
        );
        $this->assertStringContainsString('<p>', $stored, 'The snapshot must really hold markup');

        $this->setUser($teacher);
        $result = $this->sections([
            'runid' => (int) $run->id,
            'courseid' => (int) $course->id,
            'userquery' => '',
            'groupquery' => '',
            'page' => 0,
        ]);

        $texts = [];
        foreach ($result['sections'][0]['members'] as $member) {
            $texts = array_merge($texts, array_column($member['why'], 'text'));
        }
        $this->assertNotEmpty($texts);
        $joined = implode(' ', $texts);
        $this->assertStringContainsString('Recife', $joined);
        $this->assertStringNotContainsString('<p>', $joined);
    }

    /**
     * The member window is offset-addressable and reports the section total.
     */
    public function test_member_window(): void {
        $this->resetAfterTest();
        $total = auditreader::MEMBERS_PER_PAGE + 3;
        [$course, $teacher, $run] = $this->seed($total);

        $this->setUser($teacher);
        $sections = $this->sections([
            'runid' => (int) $run->id,
            'courseid' => (int) $course->id,
            'userquery' => '',
            'groupquery' => '',
            'page' => 0,
        ]);
        $groupid = $sections['sections'][0]['id'];
        $this->assertTrue($sections['sections'][0]['hasmore']);

        $window = $this->members([
            'runid' => (int) $run->id,
            'courseid' => (int) $course->id,
            'groupid' => $groupid,
            'userquery' => '',
            'limitfrom' => auditreader::MEMBERS_PER_PAGE,
        ]);
        $this->assertSame($total, $window['total']);
        $this->assertCount(3, $window['members']);
        $this->assertSame($total, $window['shown']);
    }

    /**
     * The capability is the gate: a student enrolled in the same course, who
     * can reach the context, is refused. The control is the teacher above,
     * who receives the same payload from the same call.
     */
    public function test_capability_gate(): void {
        $this->resetAfterTest();
        [$course, , $run] = $this->seed(2);
        $student = $this->getDataGenerator()->create_and_enrol($course);

        $this->setUser($student);
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_groupdist_get_audit_sections', [
            'runid' => (int) $run->id,
            'courseid' => (int) $course->id,
            'userquery' => '',
            'groupquery' => '',
            'page' => 0,
        ]);
        $this->assertTrue($response['error']);
        $this->assertSame('nopermissions', $response['exception']->errorcode);
    }

    /**
     * A run of another course is refused even when the caller may read the
     * audit log of the course they name.
     */
    public function test_run_of_another_course_is_refused(): void {
        $this->resetAfterTest();
        [, , $otherrun] = $this->seed(2);
        [$course, $teacher] = $this->seed(2);

        $this->setUser($teacher);
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_groupdist_get_audit_sections', [
            'runid' => (int) $otherrun->id,
            'courseid' => (int) $course->id,
            'userquery' => '',
            'groupquery' => '',
            'page' => 0,
        ]);
        $this->assertTrue($response['error']);
    }

    /**
     * An unknown group id is rejected rather than answered with an empty
     * window, so a probe cannot walk group ids through this function.
     */
    public function test_unknown_group_is_refused(): void {
        $this->resetAfterTest();
        [$course, $teacher, $run] = $this->seed(2);

        $this->setUser($teacher);
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_groupdist_get_audit_members', [
            'runid' => (int) $run->id,
            'courseid' => (int) $course->id,
            'groupid' => 999999,
            'userquery' => '',
            'limitfrom' => 0,
        ]);
        $this->assertTrue($response['error']);
    }
}
