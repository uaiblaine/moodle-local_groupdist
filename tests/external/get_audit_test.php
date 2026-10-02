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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Audit report web service tests: windows, search, gates and the allowlist.
 *
 * PHPUnit metadata is written as docblock tags, never attributes: Moodle 4.5
 * runs PHPUnit 9, which reads only the tags, and its moodle-cs reports every
 * test of a class without a covers tag.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\external\audit_ws
 * @covers     \local_groupdist\external\get_audit_members
 * @covers     \local_groupdist\external\get_audit_sections
 */
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
     * A section's link to its own page keeps the participant search.
     *
     * The live search replaces the cards with this payload, so a link built
     * without the search opens the whole group, not the matches on screen,
     * when it is followed outside the page's script (a new tab, a copied
     * link). The control is an empty search, which adds nothing.
     */
    public function test_section_links_keep_the_participant_search(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $run] = $this->seed(3);
        $userid = $DB->get_field_select(
            'local_groupdist_run_user',
            'userid',
            'runid = ? AND userid > 0',
            [(int) $run->id],
            IGNORE_MULTIPLE
        );
        $DB->set_field('user', 'firstname', 'Zuleica', ['id' => $userid]);

        $this->setUser($teacher);
        $args = ['runid' => (int) $run->id, 'courseid' => (int) $course->id, 'groupquery' => '', 'page' => 0];
        $searched = $this->sections($args + ['userquery' => ' Zuleica ']);
        $this->assertCount(1, $searched['sections'], 'The search matched no section, so no link was built.');
        $this->assertSame(1, $searched['matchingmembers']);
        $link = new \moodle_url($searched['sections'][0]['moreurl']);
        // The cleaned term, as audit.php's own links carry it.
        $this->assertSame('Zuleica', $link->get_param('uq'));
        $this->assertSame($searched['sections'][0]['id'], (int) $link->get_param('group'));

        $unsearched = $this->sections($args + ['userquery' => '']);
        $this->assertNull((new \moodle_url($unsearched['sections'][0]['moreurl']))->get_param('uq'));
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
     * can reach the context, is refused. The control is the teacher in
     * test_sections_payload(), who receives the payload from the same call.
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
     *
     * The error code pins the refusal to the run's course check in
     * {@see audit_ws::resolve_run()}, not to any error the call could raise.
     */
    public function test_run_of_another_course_is_refused(): void {
        $this->resetAfterTest();
        [, , $otherrun] = $this->seed(2);
        [$course, $teacher, $ownrun] = $this->seed(2);

        $this->setUser($teacher);
        $args = [
            'courseid' => (int) $course->id,
            'userquery' => '',
            'groupquery' => '',
            'page' => 0,
        ];
        // Control: the same caller reads the audit log of the course they name.
        $this->assertSame(1, $this->sections(['runid' => (int) $ownrun->id] + $args)['total']);

        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function(
            'local_groupdist_get_audit_sections',
            ['runid' => (int) $otherrun->id] + $args
        );
        $this->assertTrue($response['error']);
        $this->assertSame('invaliddata', $response['exception']->errorcode);
    }

    /**
     * An unknown group id is rejected rather than answered with an empty
     * window, so a probe cannot walk group ids through this function.
     *
     * The error code and the debug info pin the refusal to the section check
     * in {@see get_audit_members::execute()}: parameter validation raises the
     * same exception class with a different debug info.
     */
    public function test_unknown_group_is_refused(): void {
        $this->resetAfterTest();
        [$course, $teacher, $run] = $this->seed(2);
        $groupid = (int) json_decode($run->groupsjson, true)[0]['id'];
        $args = [
            'runid' => (int) $run->id,
            'courseid' => (int) $course->id,
            'userquery' => '',
            'limitfrom' => 0,
        ];

        $this->setUser($teacher);
        // Control: a group the run wrote into is answered.
        $this->assertSame(2, $this->members(['groupid' => $groupid] + $args)['total']);

        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function(
            'local_groupdist_get_audit_members',
            ['groupid' => $groupid + 999999] + $args
        );
        $this->assertTrue($response['error']);
        $this->assertSame('invalidparameter', $response['exception']->errorcode);
        $this->assertStringStartsWith('groupid', $response['exception']->debuginfo);
    }

    /**
     * With formatstringstriptags off, the snapshot group name and the stored
     * rule values still reach both services plain
     * ({@see \local_groupdist\local\plaintext::format()}).
     *
     * format_string() then spells a bare "&" as "&amp;" and a bare "<" as
     * "&lt;", which the report's double stashes would show literally. The
     * snapshot is rewritten after the run, as that is where both strings are
     * read from.
     *
     * @return void
     */
    public function test_names_and_values_are_plain_with_formatstringstriptags_off(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $run] = $this->seed(3, 'Recife', [['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]]);
        $groups = json_decode($run->groupsjson, true);
        $groups[0]['name'] = 'Turma A & B';
        $DB->set_field('local_groupdist_run', 'groupsjson', json_encode($groups), ['id' => (int) $run->id]);
        $rows = array_values($DB->get_records('local_groupdist_run_user', ['runid' => (int) $run->id], 'id'));
        $this->assertCount(3, $rows);
        foreach (['Manha & Tarde', 'Manha & Tarde', 'Turno <3 anos'] as $i => $value) {
            $DB->set_field('local_groupdist_run_user', 'valuesjson', json_encode([$value]), ['id' => (int) $rows[$i]->id]);
        }
        set_config('formatstringstriptags', 0);

        $label = json_decode($run->rulesjson, true)['rules'][0]['label'];
        $line = function (string $value, int $count) use ($label): string {
            return get_string('auditwhytogether', 'local_groupdist', (object) [
                'index' => 1,
                'label' => $label,
                'value' => $value,
                'count' => $count,
            ]);
        };
        $expected = [$line('Manha & Tarde', 1), $line('Manha & Tarde', 1), $line('Turno ', 0)];
        sort($expected);

        $this->setUser($teacher);
        $sections = $this->sections([
            'runid' => (int) $run->id,
            'courseid' => (int) $course->id,
            'userquery' => '',
            'groupquery' => '',
            'page' => 0,
        ]);
        $this->assertSame('Turma A & B', $sections['sections'][0]['name']);
        $members = $this->members([
            'runid' => (int) $run->id,
            'courseid' => (int) $course->id,
            'groupid' => (int) $groups[0]['id'],
            'userquery' => '',
            'limitfrom' => 0,
        ])['members'];

        foreach ([$sections['sections'][0]['members'], $members] as $window) {
            $this->assertSame(['Turma A & B'], array_values(array_unique(array_column($window, 'groupname'))));
            $texts = [];
            foreach ($window as $member) {
                $texts = array_merge($texts, array_column($member['why'], 'text'));
            }
            sort($texts);
            $this->assertSame($expected, $texts);
        }
    }
}
