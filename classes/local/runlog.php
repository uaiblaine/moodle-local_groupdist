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
 * Audit log writer: one snapshot per applied distribution run.
 *
 * Snapshot, not references: profile fields, cohorts and groups change after a
 * run, so every row records the data as it was at apply time — the ruleset
 * with labels resolved then, each participant's per-rule values (exactly the
 * allocator's input matrix, so capture costs nothing) and the write outcome
 * per member. The audit UI derives its explanations from these stored facts,
 * never by replaying the engine, which keeps old runs readable across engine
 * upgrades.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runlog {
    /** @var int Run status: created, apply not finished yet. */
    public const STATUS_PENDING = 0;

    /** @var int Run status: every planned membership was written. */
    public const STATUS_COMPLETED = 1;

    /** @var int Run status: finished with rejected writes. */
    public const STATUS_PARTIAL = 2;

    /** @var int Run status: aborted before writing (stale fingerprint). */
    public const STATUS_ABORTED = 3;

    /** @var int Member outcome: write planned, not performed yet. */
    public const WRITE_PLANNED = 0;

    /** @var int Member outcome: membership written (or already present). */
    public const WRITE_WRITTEN = 1;

    /** @var int Member outcome: rejected by core (deleted/unenrolled meanwhile). */
    public const WRITE_FAILED = 2;

    /** @var int Member outcome: no group had capacity left. */
    public const WRITE_UNASSIGNED = 3;

    /** @var int Member outcome: no write needed (already sat with their peers). */
    public const WRITE_SKIPPED = 4;

    /**
     * Record the snapshot of a run about to be applied.
     *
     * @param distribution $distribution The computed distribution.
     * @param int $userid Who is applying.
     * @param \core\context\course $context The course context (label resolution).
     * @return int The run id.
     */
    public static function create(distribution $distribution, int $userid, \core\context\course $context): int {
        global $DB;

        $options = $distribution->options;
        $rules = [];
        foreach ($options->affinityrules->get_rules() as $rule) {
            $rules[] = $rule + ['label' => profilefields::get_label($rule['source'], $context)];
        }
        $groups = [];
        foreach ($distribution->groups as $group) {
            $groups[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'seats' => $group['seats'],
                'current' => $group['current'],
            ];
        }

        $run = (object) [
            'courseid' => $options->courseid,
            'userid' => $userid,
            'status' => self::STATUS_PENDING,
            'seed' => $options->seed,
            'fingerprint' => $distribution->fingerprint,
            'pluginversion' => (int) get_config('local_groupdist', 'version'),
            'restored' => 0,
            'optionsjson' => json_encode($options->to_array()),
            'rulesjson' => json_encode(['v' => ruleset::VERSION, 'rules' => $rules]),
            'groupsjson' => json_encode($groups),
            'warningsjson' => json_encode($distribution->warnings),
            'memberstotal' => $distribution->allocation->count_memberships(),
            'memberswritten' => 0,
            'timecreated' => time(),
            'timecompleted' => 0,
        ];
        $runid = $DB->insert_record('local_groupdist_run', $run);

        $plannedgroup = [];
        foreach ($distribution->allocation->assignments as $groupid => $userids) {
            foreach ($userids as $memberid) {
                $plannedgroup[$memberid] = (int) $groupid;
            }
        }
        $unassigned = array_fill_keys($distribution->allocation->unassigned, true);

        $rulecount = $options->affinityrules->count();
        $rows = [];
        foreach ($distribution->users as $user) {
            $memberid = (int) $user->id;
            $values = [];
            for ($i = 0; $i < $rulecount; $i++) {
                $values[] = trim((string) ($user->{'affinity' . $i} ?? ''));
            }
            if (isset($plannedgroup[$memberid])) {
                $groupid = $plannedgroup[$memberid];
                $writestatus = self::WRITE_PLANNED;
            } else if (isset($unassigned[$memberid])) {
                $groupid = 0;
                $writestatus = self::WRITE_UNASSIGNED;
            } else {
                // Candidates the engine dropped silently: they already sat in
                // the group their cluster landed on — nothing to write.
                $groupid = 0;
                $writestatus = self::WRITE_SKIPPED;
            }
            $rows[] = (object) [
                'runid' => $runid,
                'userid' => $memberid,
                'valuesjson' => json_encode($values),
                'groupid' => $groupid,
                'writestatus' => $writestatus,
            ];
        }
        if ($rows) {
            $DB->insert_records('local_groupdist_run_user', $rows);
        }
        return (int) $runid;
    }

    /**
     * Seal a run after the applier finished.
     *
     * Planned members become written except the reported failures — the plan
     * and the outcome may diverge on a partial apply, and the log records the
     * outcome.
     *
     * @param int $runid The run id.
     * @param array $summary Applier summary: 'added', 'failed' and
     *   'failedpairs' (list of [groupid, userid]).
     * @return void
     */
    public static function complete(int $runid, array $summary): void {
        global $DB;

        foreach (($summary['failedpairs'] ?? []) as [$groupid, $userid]) {
            $DB->set_field(
                'local_groupdist_run_user',
                'writestatus',
                self::WRITE_FAILED,
                ['runid' => $runid, 'userid' => $userid, 'groupid' => $groupid]
            );
        }
        $DB->set_field_select(
            'local_groupdist_run_user',
            'writestatus',
            self::WRITE_WRITTEN,
            'runid = :runid AND writestatus = :planned AND groupid <> 0',
            ['runid' => $runid, 'planned' => self::WRITE_PLANNED]
        );

        $failed = (int) ($summary['failed'] ?? 0);
        $run = (object) [
            'id' => $runid,
            'status' => ($failed > 0) ? self::STATUS_PARTIAL : self::STATUS_COMPLETED,
            'memberswritten' => (int) ($summary['added'] ?? 0),
            'timecompleted' => time(),
        ];
        $DB->update_record('local_groupdist_run', $run);
    }

    /**
     * Mark a run as aborted before anything was written (stale fingerprint).
     *
     * Member rows keep their planned status — accurately: no write happened.
     *
     * @param int $runid The run id.
     * @return void
     */
    public static function abort(int $runid): void {
        global $DB;

        $DB->update_record('local_groupdist_run', (object) [
            'id' => $runid,
            'status' => self::STATUS_ABORTED,
            'timecompleted' => time(),
        ]);
    }

    /**
     * Delete every run of a course (course deletion; the recycle bin keeps a
     * backup file, not the course, so the rows would be unreachable orphans).
     *
     * @param int $courseid The course id.
     * @return void
     */
    public static function purge_course(int $courseid): void {
        global $DB;

        $runids = $DB->get_fieldset_select('local_groupdist_run', 'id', 'courseid = :courseid', ['courseid' => $courseid]);
        foreach (array_chunk($runids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'r');
            $DB->delete_records_select('local_groupdist_run_user', "runid {$insql}", $params);
        }
        $DB->delete_records('local_groupdist_run', ['courseid' => $courseid]);
    }

    /**
     * Pseudonymise every row of a user, everywhere.
     *
     * The rows survive anonymised so run counts and group compositions stay
     * intact; the audit UI shows such members as removed participants.
     *
     * @param int $userid The user id.
     * @return void
     */
    public static function pseudonymise_user(int $userid): void {
        global $DB;

        $DB->execute(
            "UPDATE {local_groupdist_run_user} SET userid = 0, valuesjson = '' WHERE userid = :userid",
            ['userid' => $userid]
        );
        $DB->set_field('local_groupdist_run', 'userid', 0, ['userid' => $userid]);
    }

    /**
     * Pseudonymise a user's rows within one course only (privacy requests).
     *
     * @param int $userid The user id.
     * @param int $courseid The course id.
     * @return void
     */
    public static function pseudonymise_user_in_course(int $userid, int $courseid): void {
        global $DB;

        $runids = $DB->get_fieldset_select('local_groupdist_run', 'id', 'courseid = :courseid', ['courseid' => $courseid]);
        foreach (array_chunk($runids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'r');
            $DB->execute(
                "UPDATE {local_groupdist_run_user} SET userid = 0, valuesjson = ''
                  WHERE userid = :userid AND runid {$insql}",
                $params + ['userid' => $userid]
            );
        }
        $DB->set_field('local_groupdist_run', 'userid', 0, ['userid' => $userid, 'courseid' => $courseid]);
    }
}
