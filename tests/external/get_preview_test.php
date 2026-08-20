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
use local_groupdist\local\fields;

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
     * The location label survives the web service unescaped.
     *
     * This is the one path where the escape => false rule could have gone the
     * other way: the value is declared PARAM_TEXT, so it passes through
     * clean_returnvalue() before the client sees it. PARAM_TEXT only handles
     * tags and multilang markup (core\param::clean_param_value_text) and never
     * touches entities, so an ampersand arrives intact — and it has to, because
     * preview.js hands the value to a Mustache double stash and writes warning
     * messages with textContent, both of which escape for themselves.
     *
     * @return void
     */
    public function test_the_location_label_crosses_the_web_service_unescaped(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, , $args] = $this->make_course(2, 4);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setAdminUser();
        fields::reset_field_cache();
        fields::ensure_fields_exist();
        fields::reset_field_cache();
        $DB->set_field('customfield_field', 'name', 'Local & Sala', ['id' => fields::get_location_field()->get('id')]);
        fields::reset_field_cache();
        $this->setUser($teacher);

        $response = $this->call($args + ['limitfrom' => 0, 'limitnum' => 2]);

        $this->assertSame('Local & Sala', $response['locationlabel']);
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
            $args + ['affinityrules' => [['source' => 'profile_999999', 'mode' => 'together']]]
        );
        $this->assertTrue($response['error']);
    }

    /**
     * Multi-rule payload: per-member badge lists, per-group rule status and
     * the global rules report all survive the return-structure allowlist.
     */
    public function test_preview_multi_rule_payload(): void {
        $this->resetAfterTest();
        [$course, , $args] = $this->make_course(2, 0);
        $generator = $this->getDataGenerator();
        foreach ([['Ana', 'Lima', 'X'], ['Bia', 'Melo', 'X'], ['Caio', 'Reis', 'Y']] as [$first, $last, $city]) {
            $user = $generator->create_and_enrol($course);
            $user->firstname = $first;
            $user->lastname = $last;
            $user->city = $city;
            $user->department = 'D1';
            user_update_user($user, false);
        }
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->call($args + [
            'roleid' => (int) current(get_archetype_roles('student'))->id,
            'affinityrules' => [
                ['source' => 'city', 'mode' => 'together'],
                ['source' => 'department', 'mode' => 'apart'],
            ],
        ]);

        // Rules report: one section per rule; city X clusters two students.
        $this->assertCount(2, $result['rulereport']);
        $this->assertSame(1, $result['rulereport'][0]['index']);
        $this->assertSame('X', $result['rulereport'][0]['entries'][0]['value']);
        $this->assertSame(2, $result['rulereport'][0]['entries'][0]['count']);

        // Group cards: per-rule status footer and per-member badge lists.
        foreach ($result['groups'] as $group) {
            $this->assertCount(2, $group['rules']);
            foreach ($group['members'] as $member) {
                $this->assertArrayHasKey('affinities', $member);
            }
        }
    }

    /**
     * A profile value containing a bare "<" must not take the whole preview
     * down.
     *
     * The vector is a TEXTAREA custom profile field, and it is the only one
     * that reaches this: profile_field_textarea declares PARAM_RAW with the
     * comment "We MUST clean this before display!"
     * (user/profile/field/textarea/field.class.php:40), while the standard
     * fields self-sanitise — user_update_user() runs city/department/
     * institution through core_user::clean_field() with PARAM_TEXT — and
     * profilefields::get_fields() offers every custom field with no filter on
     * datatype. The value then reaches the payload straight from
     * {user_info_data} with no format_string() on the path, into PARAM_TEXT
     * return fields, where clean_param_value_text()'s strip_tags() eats the
     * tail and validate_param() throws because cleaned !== original. One
     * participant used to fail every page of the preview for everyone.
     *
     * @return void
     */
    public function test_a_profile_value_with_an_angle_bracket_does_not_break_the_preview(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $this->resetAfterTest();
        [$course, , $args] = $this->make_course(2, 0);
        $generator = $this->getDataGenerator();
        $field = $generator->create_custom_profile_field([
            'datatype' => 'textarea',
            'shortname' => 'notes',
            'name' => 'Notes',
        ]);

        foreach (['Turno <3 anos', 'Turno <3 anos', 'Sem marca'] as $value) {
            $user = $generator->create_and_enrol($course);
            profile_save_data((object) ['id' => $user->id, 'profile_field_notes' => $value]);
        }
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->call($args + [
            'roleid' => (int) current(get_archetype_roles('student'))->id,
            'affinityrules' => [['source' => 'profile_' . $field->id, 'mode' => 'together']],
        ]);

        /* The report lists clustered values only, so the two matching
           students are the single entry. The tag-looking tail is stripped, as
           it is everywhere format_string runs. */
        $this->assertCount(1, $result['rulereport'][0]['entries']);
        $entry = $result['rulereport'][0]['entries'][0];
        $this->assertSame(2, $entry['count']);
        $this->assertStringStartsWith('Turno', $entry['value']);
        $this->assertStringNotContainsString('<', $entry['value'], 'A bare "<" reached a PARAM_TEXT field.');

        $badges = [];
        foreach ($result['groups'] as $group) {
            foreach ($group['members'] as $member) {
                foreach ($member['affinities'] as $affinity) {
                    $badges[] = $affinity['value'];
                }
            }
        }
        $this->assertNotEmpty($badges, 'The affinity badges were not built at all.');
        foreach ($badges as $badge) {
            $this->assertStringNotContainsString('<', $badge);
        }
    }

    /**
     * Markup in the same field is stripped rather than carried into the
     * payload — the preview renders these values through a Mustache double
     * stash, but a textarea field can hold a whole document and there is no
     * reason for any of it to travel.
     *
     * @return void
     */
    public function test_markup_in_a_profile_value_is_stripped(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $this->resetAfterTest();
        [$course, , $args] = $this->make_course(2, 0);
        $generator = $this->getDataGenerator();
        $field = $generator->create_custom_profile_field([
            'datatype' => 'textarea',
            'shortname' => 'notes',
            'name' => 'Notes',
        ]);

        foreach (['<b>Manha</b>', '<b>Manha</b>'] as $value) {
            $user = $generator->create_and_enrol($course);
            profile_save_data((object) ['id' => $user->id, 'profile_field_notes' => $value]);
        }
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->call($args + [
            'roleid' => (int) current(get_archetype_roles('student'))->id,
            'affinityrules' => [['source' => 'profile_' . $field->id, 'mode' => 'together']],
        ]);

        $values = array_column($result['rulereport'][0]['entries'], 'value');
        $this->assertContains('Manha', $values);
        $this->assertNotContains('<b>Manha</b>', $values);
    }

    /**
     * The warning sentences carry the raw value too, and are the branch a
     * "together"-only fixture never reaches.
     *
     * Three students sharing one value under a keep-apart rule cannot all be
     * separated across two groups, so the allocator raises WARNING_APART and
     * the value is interpolated into the message — which is a PARAM_TEXT
     * field like the rest.
     *
     * @return void
     */
    public function test_a_warning_message_carrying_a_raw_value_survives_the_allowlist(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $this->resetAfterTest();
        [$course, , $args] = $this->make_course(2, 0);
        $generator = $this->getDataGenerator();
        $field = $generator->create_custom_profile_field([
            'datatype' => 'textarea',
            'shortname' => 'notes',
            'name' => 'Notes',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $user = $generator->create_and_enrol($course);
            profile_save_data((object) ['id' => $user->id, 'profile_field_notes' => 'Turma <3 X']);
        }
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->call($args + [
            'roleid' => (int) current(get_archetype_roles('student'))->id,
            'affinityrules' => [['source' => 'profile_' . $field->id, 'mode' => 'apart']],
        ]);

        $messages = array_column($result['warnings'], 'message');
        $this->assertNotEmpty($messages, 'The keep-apart rule raised no warning, so nothing was covered.');
        $carrying = array_filter($messages, static function (string $message): bool {
            return str_contains($message, 'Turma');
        });
        $this->assertNotEmpty($carrying, 'No warning interpolated the value.');
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('<', $message);
        }
    }

    /**
     * A hidden cohort as a RULE source is rejected — same oracle rule as the
     * cohort member filter. A visible cohort passes (control).
     */
    public function test_preview_rejects_hidden_cohort_rule(): void {
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
            $args + ['affinityrules' => [['source' => 'cohort_' . $hidden->id, 'mode' => 'apart']]]
        );
        $this->assertTrue($response['error']);

        // Three enrolled users plus the acting teacher (roleid 0 = any role).
        $control = $this->call(
            $args + ['affinityrules' => [['source' => 'cohort_' . $visible->id, 'mode' => 'apart']]]
        );
        $this->assertSame(4, $control['totals']['candidates']);
    }
}
