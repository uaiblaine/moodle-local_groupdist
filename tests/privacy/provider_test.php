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

namespace local_groupdist\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_groupdist\local\distribution;
use local_groupdist\local\options;
use local_groupdist\local\runlog;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Privacy provider tests over the audit log.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\privacy\provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Seed a course with an applied-style run; returns the actors.
     *
     * @return array [course, context, applierid, participantid, runid].
     */
    private function seed(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        $participant = $generator->create_and_enrol($course);
        $participant->city = 'Fortaleza';
        user_update_user($participant, false);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'affinityrules' => [['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]],
            'seed' => 3,
            'roleid' => (int) current(get_archetype_roles('student'))->id,
        ]);
        $this->setUser($teacher);
        $distribution = distribution::build($options, $context);
        $runid = runlog::create($distribution, (int) $teacher->id, $context);
        return [$course, $context, (int) $teacher->id, (int) $participant->id, $runid];
    }

    /**
     * Both the applier and the participants get the course context listed.
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        [, $context, $applierid, $participantid] = $this->seed();

        $this->assertContains(
            (int) $context->id,
            array_map('intval', provider::get_contexts_for_userid($applierid)->get_contextids())
        );
        $this->assertContains(
            (int) $context->id,
            array_map('intval', provider::get_contexts_for_userid($participantid)->get_contextids())
        );

        $outsider = $this->getDataGenerator()->create_user();
        $this->assertNotContains(
            (int) $context->id,
            array_map('intval', provider::get_contexts_for_userid((int) $outsider->id)->get_contextids())
        );
    }

    /**
     * The userlist covers appliers and participants.
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        [, $context, $applierid, $participantid] = $this->seed();

        $userlist = new userlist($context, 'local_groupdist');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();

        $this->assertContains($applierid, $userids);
        $this->assertContains($participantid, $userids);
    }

    /**
     * Export produces the participant's rule values and outcomes.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        [, $context, , $participantid] = $this->seed();

        $this->export_context_data_for_user($participantid, $context, 'local_groupdist');
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'local_groupdist')]);

        $this->assertNotEmpty($data->participations);
        $this->assertSame(['Fortaleza'], (array) $data->participations[0]['rulevalues']);
    }

    /**
     * A user deletion request pseudonymises: rows survive with the userid
     * zeroed and values blanked; other users stay intact (control).
     */
    public function test_delete_data_for_user_pseudonymises(): void {
        global $DB;
        $this->resetAfterTest();
        [, $context, $applierid, $participantid, $runid] = $this->seed();

        $participant = \core_user::get_user($participantid, '*', MUST_EXIST);
        provider::delete_data_for_user(new approved_contextlist($participant, 'local_groupdist', [$context->id]));

        $this->assertSame(1, $DB->count_records('local_groupdist_run_user', ['runid' => $runid]));
        $row = $DB->get_record('local_groupdist_run_user', ['runid' => $runid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->userid);
        $this->assertSame('', $row->valuesjson);
        // Control: the applier's run header is untouched by the participant's request.
        $this->assertSame($applierid, (int) $DB->get_field('local_groupdist_run', 'userid', ['id' => $runid]));
    }

    /**
     * Deleting everything in the context wipes the audit rows.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        [, $context, , , $runid] = $this->seed();

        provider::delete_data_for_all_users_in_context($context);

        $this->assertSame(0, $DB->count_records('local_groupdist_run', ['id' => $runid]));
        $this->assertSame(0, $DB->count_records('local_groupdist_run_user', ['runid' => $runid]));
    }

    /**
     * The userlist deletion pseudonymises the approved users only.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        [, $context, $applierid, $participantid, $runid] = $this->seed();

        provider::delete_data_for_users(new approved_userlist($context, 'local_groupdist', [$participantid]));

        $row = $DB->get_record('local_groupdist_run_user', ['runid' => $runid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->userid);
        // Control: the applier (not approved) keeps their run header.
        $this->assertSame($applierid, (int) $DB->get_field('local_groupdist_run', 'userid', ['id' => $runid]));
    }
}
