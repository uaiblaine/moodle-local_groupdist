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
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_groupdist\local\runlog;

/**
 * Privacy provider.
 *
 * The audit log stores personal data by design: each applied run snapshots
 * who ran it and, per participant, the rule values at apply time (profile
 * field values, cohort membership flags), the planned group and the write
 * outcome. Deletion requests pseudonymise the rows (userid zeroed, values
 * blanked) instead of removing them, so run counts and group compositions
 * stay intact for the remaining participants. The bulk edit column
 * preference is the only other personal data.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Describe the stored personal data.
     *
     * @param collection $collection The metadata collection.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_groupdist_run', [
            'courseid' => 'privacy:metadata:local_groupdist_run:courseid',
            'userid' => 'privacy:metadata:local_groupdist_run:userid',
            'status' => 'privacy:metadata:local_groupdist_run:status',
            'optionsjson' => 'privacy:metadata:local_groupdist_run:optionsjson',
            'rulesjson' => 'privacy:metadata:local_groupdist_run:rulesjson',
            'timecreated' => 'privacy:metadata:local_groupdist_run:timecreated',
        ], 'privacy:metadata:local_groupdist_run');

        $collection->add_database_table('local_groupdist_run_user', [
            'userid' => 'privacy:metadata:local_groupdist_run_user:userid',
            'valuesjson' => 'privacy:metadata:local_groupdist_run_user:valuesjson',
            'groupid' => 'privacy:metadata:local_groupdist_run_user:groupid',
            'writestatus' => 'privacy:metadata:local_groupdist_run_user:writestatus',
        ], 'privacy:metadata:local_groupdist_run_user');

        $collection->add_user_preference(
            'local_groupdist_bulkedit_hiddencols',
            'privacy:metadata:preference:bulkeditcols'
        );
        return $collection;
    }

    /**
     * Course contexts holding data of a user (as applier or as participant).
     *
     * @param int $userid The user id.
     * @return contextlist The contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_groupdist_run} r ON r.courseid = ctx.instanceid AND ctx.contextlevel = :courselevel
              WHERE r.userid = :userid",
            ['courselevel' => CONTEXT_COURSE, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_groupdist_run} r ON r.courseid = ctx.instanceid AND ctx.contextlevel = :courselevel
               JOIN {local_groupdist_run_user} ru ON ru.runid = r.id
              WHERE ru.userid = :userid",
            ['courselevel' => CONTEXT_COURSE, 'userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * Users with data in one context.
     *
     * @param userlist $userlist The userlist to fill.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \core\context\course) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            'SELECT r.userid FROM {local_groupdist_run} r WHERE r.courseid = :courseid AND r.userid > 0',
            ['courseid' => $context->instanceid]
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT ru.userid
               FROM {local_groupdist_run_user} ru
               JOIN {local_groupdist_run} r ON r.id = ru.runid
              WHERE r.courseid = :courseid AND ru.userid > 0',
            ['courseid' => $context->instanceid]
        );
    }

    /**
     * Export a user's audit data per approved course context.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \core\context\course) {
                continue;
            }
            $courseid = (int) $context->instanceid;

            $applied = [];
            $runs = $DB->get_records('local_groupdist_run', ['courseid' => $courseid, 'userid' => $userid], 'id');
            foreach ($runs as $run) {
                $applied[] = [
                    'timecreated' => \core_privacy\local\request\transform::datetime($run->timecreated),
                    'status' => (int) $run->status,
                    'options' => json_decode($run->optionsjson),
                    'rules' => json_decode($run->rulesjson),
                ];
            }

            $participations = [];
            $rows = $DB->get_records_sql(
                'SELECT ru.id, ru.valuesjson, ru.groupid, ru.writestatus, r.timecreated
                   FROM {local_groupdist_run_user} ru
                   JOIN {local_groupdist_run} r ON r.id = ru.runid
                  WHERE r.courseid = :courseid AND ru.userid = :userid
               ORDER BY ru.id',
                ['courseid' => $courseid, 'userid' => $userid]
            );
            foreach ($rows as $row) {
                $participations[] = [
                    'timecreated' => \core_privacy\local\request\transform::datetime($row->timecreated),
                    'rulevalues' => json_decode($row->valuesjson),
                    'groupid' => (int) $row->groupid,
                    'writestatus' => (int) $row->writestatus,
                ];
            }

            if ($applied || $participations) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_groupdist')],
                    (object) ['appliedruns' => $applied, 'participations' => $participations]
                );
            }
        }
    }

    /**
     * Wipe the audit log of one context entirely.
     *
     * @param \context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context instanceof \core\context\course) {
            runlog::purge_course((int) $context->instanceid);
        }
    }

    /**
     * Pseudonymise one user's rows in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \core\context\course) {
                runlog::pseudonymise_user_in_course($userid, (int) $context->instanceid);
            }
        }
    }

    /**
     * Pseudonymise several users' rows in one context.
     *
     * @param approved_userlist $userlist The approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \core\context\course) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            runlog::pseudonymise_user_in_course((int) $userid, (int) $context->instanceid);
        }
    }

    /**
     * Export the user preference.
     *
     * @param int $userid The user id.
     * @return void
     */
    public static function export_user_preferences(int $userid): void {
        $value = get_user_preferences('local_groupdist_bulkedit_hiddencols', null, $userid);
        if ($value !== null) {
            writer::export_user_preference(
                'local_groupdist',
                'local_groupdist_bulkedit_hiddencols',
                $value,
                get_string('privacy:metadata:preference:bulkeditcols', 'local_groupdist')
            );
        }
    }
}
