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

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\user_preference;
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
     * The plugin counts as compliant with the privacy API.
     *
     * Core's own compliance test sweeps every component but belongs to core_privacy_testsuite,
     * not to this plugin's testsuite, so the check is repeated here: a metadata provider
     * without a request data provider fails it ({@see \core_privacy\manager::component_is_compliant()}).
     *
     * @return void
     */
    public function test_the_component_is_compliant(): void {
        $this->assertTrue((new \core_privacy\manager())->component_is_compliant('local_groupdist'));
    }

    /**
     * Every user-linked table in install.xml is declared in the metadata with
     * its user columns, and so is the bulk edit preference.
     *
     * component_is_compliant() does not look at tables. Core's
     * test_table_coverage() does, by the same rule as below (a userid column or
     * a foreign key to user.id), but it is not in this plugin's testsuite.
     *
     * @return void
     */
    public function test_the_metadata_declares_each_user_linked_table(): void {
        global $DB;
        // Loads the xmldb classes.
        $DB->get_manager();

        $xmldbfile = new \xmldb_file(dirname(__DIR__, 2) . '/db/install.xml');
        $this->assertTrue($xmldbfile->loadXMLStructure());
        $userlinked = [];
        foreach ($xmldbfile->getStructure()->getTables() as $table) {
            foreach ($table->getKeys() as $key) {
                if ($key->getRefTable() === 'user' && $key->getRefFields() === ['id'] && count($key->getFields()) === 1) {
                    $userlinked[$table->getName()][] = $key->getFields()[0];
                }
            }
            if ($table->getField('userid')) {
                $userlinked[$table->getName()][] = 'userid';
            }
        }
        // Also the precondition: an unparsed file would leave nothing to check.
        $this->assertEqualsCanonicalizing(
            ['local_groupdist_run', 'local_groupdist_run_user'],
            array_keys($userlinked)
        );

        $declared = [];
        $preferences = [];
        foreach (provider::get_metadata(new collection('local_groupdist'))->get_collection() as $item) {
            if ($item instanceof database_table) {
                $declared[$item->get_name()] = array_keys($item->get_privacy_fields());
            } else if ($item instanceof user_preference) {
                $preferences[] = $item->get_name();
            }
        }
        foreach ($userlinked as $tablename => $userfields) {
            $this->assertArrayHasKey($tablename, $declared, $tablename . ' holds user data but is not declared.');
            foreach (array_unique($userfields) as $userfield) {
                $this->assertContains($userfield, $declared[$tablename], $tablename . '.' . $userfield . ' is not declared.');
            }
        }
        $this->assertContains('valuesjson', $declared['local_groupdist_run_user']);
        $this->assertSame(['local_groupdist_bulkedit_hiddencols'], $preferences);
    }

    /**
     * The bulk edit preference is exported when set, and nothing is when not.
     *
     * The first half is the control: without it the second would pass against
     * a provider that exported a value for everybody.
     *
     * @return void
     */
    public function test_the_bulk_edit_preference_is_exported_only_when_set(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $system = \core\context\system::instance();

        provider::export_user_preferences((int) $user->id);
        $this->assertFalse(writer::with_context($system)->has_any_data());

        set_user_preference('local_groupdist_bulkedit_hiddencols', 'location,members', $user);
        provider::export_user_preferences((int) $user->id);
        $exported = writer::with_context($system)->get_user_preferences('local_groupdist');

        $this->assertSame('location,members', $exported->local_groupdist_bulkedit_hiddencols->value);
        $this->assertSame(
            get_string('privacy:metadata:preference:bulkeditcols', 'local_groupdist'),
            $exported->local_groupdist_bulkedit_hiddencols->description
        );
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
     * A course where one user holds a membership the plugin wrote and a manual one.
     *
     * The manual membership (empty component) is core_group's to export and
     * delete, so every test below uses it as the control that this provider
     * touches its own rows only. The user has no audit row, so finding the
     * course for them proves the membership arm of the provider on its own.
     *
     * @return array [context, member, plugin group id, manual group id].
     */
    private function seed_memberships(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $plugingroup = $generator->create_group(['courseid' => $course->id, 'name' => 'Turma A']);
        $manualgroup = $generator->create_group(['courseid' => $course->id, 'name' => 'Turma B']);
        $member = $generator->create_and_enrol($course);
        $this->assertTrue(groups_add_member($plugingroup, $member, 'local_groupdist', 7));
        $this->assertTrue(groups_add_member($manualgroup, $member));
        return [$context, $member, (int) $plugingroup->id, (int) $manualgroup->id];
    }

    /**
     * Whether a membership row exists, read from the table.
     *
     * @param int $groupid The group id.
     * @param int $userid The user id.
     * @return bool True when the row exists.
     */
    private function is_member(int $groupid, int $userid): bool {
        global $DB;
        return $DB->record_exists('groups_members', ['groupid' => $groupid, 'userid' => $userid]);
    }

    /**
     * The metadata links core_group, whose rows the plugin writes.
     *
     * @return void
     */
    public function test_the_metadata_links_core_group(): void {
        $collection = provider::get_metadata(new collection('local_groupdist'));
        $links = array_filter($collection->get_collection(), static function ($item): bool {
            return $item instanceof \core_privacy\local\metadata\types\subsystem_link;
        });
        $this->assertSame(['core_group'], array_values(array_map(static function ($item): string {
            return $item->get_name();
        }, $links)));
    }

    /**
     * A user whose only data is a membership the plugin wrote is found in the
     * course, by context and by userlist, and the export carries that group
     * and not the manual one, whoever runs the export.
     *
     * @return void
     */
    public function test_plugin_memberships_are_found_and_exported(): void {
        $this->resetAfterTest();
        [$context, $member, , $manualid] = $this->seed_memberships();
        $generator = $this->getDataGenerator();
        $outsider = $generator->create_user();
        $manualonly = $generator->create_and_enrol(get_course((int) $context->instanceid));
        $this->assertTrue(groups_add_member($manualid, $manualonly->id));

        $contextids = static function (int $userid): array {
            return array_map('intval', provider::get_contexts_for_userid($userid)->get_contextids());
        };
        $this->assertContains((int) $context->id, $contextids((int) $member->id));
        // Controls: no membership, and a manual membership only, are not this provider's.
        $this->assertNotContains((int) $context->id, $contextids((int) $outsider->id));
        $this->assertNotContains((int) $context->id, $contextids((int) $manualonly->id));

        $userlist = new userlist($context, 'local_groupdist');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $member->id, $userlist->get_userids());
        $this->assertNotContains((int) $manualonly->id, $userlist->get_userids());

        /* Exported by someone else, as tool_dataprivacy's task does, who holds a
           plugin membership of their own: core_group's export_groups() would read
           that $USER instead of the request's subject and export "Turma C". */
        $other = $generator->create_group(['courseid' => $context->instanceid, 'name' => 'Turma C']);
        $generator->enrol_user((int) get_admin()->id, (int) $context->instanceid);
        $this->assertTrue(groups_add_member($other, get_admin(), 'local_groupdist', 7));
        $this->setAdminUser();
        $this->export_context_data_for_user((int) $member->id, $context, 'local_groupdist');
        $data = writer::with_context($context)->get_data([
            get_string('pluginname', 'local_groupdist'),
            get_string('groups', 'core_group'),
        ]);
        $this->assertNotEmpty($data->groups);
        $this->assertSame(['Turma A'], array_values(array_map(static function ($group): string {
            return $group->name;
        }, (array) $data->groups)));
    }

    /**
     * A deletion request for one user removes the memberships the plugin
     * wrote for them and leaves the manual one to core_group.
     *
     * @return void
     */
    public function test_delete_data_for_user_removes_plugin_memberships(): void {
        $this->resetAfterTest();
        [$context, $member, $pluginid, $manualid] = $this->seed_memberships();

        provider::delete_data_for_user(new approved_contextlist($member, 'local_groupdist', [$context->id]));

        $this->assertFalse($this->is_member($pluginid, (int) $member->id));
        $this->assertTrue($this->is_member($manualid, (int) $member->id));
    }

    /**
     * A userlist deletion removes the approved users' plugin memberships only.
     *
     * @return void
     */
    public function test_delete_data_for_users_removes_plugin_memberships(): void {
        $this->resetAfterTest();
        [$context, $member, $pluginid, $manualid] = $this->seed_memberships();
        $other = $this->getDataGenerator()->create_and_enrol(get_course((int) $context->instanceid));
        $this->assertTrue(groups_add_member($pluginid, $other->id, 'local_groupdist', 7));

        provider::delete_data_for_users(new approved_userlist($context, 'local_groupdist', [(int) $member->id]));

        $this->assertFalse($this->is_member($pluginid, (int) $member->id));
        $this->assertTrue($this->is_member($manualid, (int) $member->id));
        // Control: a user who was not approved keeps the plugin membership.
        $this->assertTrue($this->is_member($pluginid, (int) $other->id));
    }

    /**
     * Deleting a whole context removes every plugin membership in it and no manual one.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_removes_plugin_memberships(): void {
        $this->resetAfterTest();
        [$context, $member, $pluginid, $manualid] = $this->seed_memberships();

        provider::delete_data_for_all_users_in_context($context);

        $this->assertFalse($this->is_member($pluginid, (int) $member->id));
        $this->assertTrue($this->is_member($manualid, (int) $member->id));
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
