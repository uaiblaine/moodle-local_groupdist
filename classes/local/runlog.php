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

    /** @var int Run status: aborted on a stale fingerprint; an earlier interrupted attempt may have written some members. */
    public const STATUS_ABORTED = 3;

    /** @var int Member outcome: write planned, not performed yet. */
    public const WRITE_PLANNED = 0;

    /** @var int Member outcome: membership written (or already present). */
    public const WRITE_WRITTEN = 1;

    /** @var int Member outcome: rejected by core (deleted/unenrolled meanwhile). */
    public const WRITE_FAILED = 2;

    /** @var int Member outcome: no selected group had room left. */
    public const WRITE_UNASSIGNED = 3;

    /** @var int Member outcome: no write needed (already in a selected group, so counted as placed). */
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
                // Candidates already in a selected group: the engine keeps them
                // there and counts them as placed, so there is nothing to write.
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
     * Mark a run as aborted on a stale fingerprint, recording what it wrote.
     *
     * The aborting attempt writes nothing, but a retried adhoc task may follow
     * an attempt that committed some chunks and then died. Those
     * memberships carry this run's stamp (component local_groupdist, itemid =
     * the seed), so each planned member whose planned membership exists with
     * that stamp is marked written, and memberswritten counts them. A
     * membership added any other way stays planned: this run did not write it.
     *
     * A missing run (its course deleted, or the retention task purged it) is
     * not an error: the caller is the adhoc task's stale branch, which must
     * not throw.
     *
     * @param int $runid The run id.
     * @return int Memberships an earlier attempt of this run had written (0 on a
     *   first attempt, and when the run no longer exists).
     */
    public static function abort(int $runid): int {
        global $DB;

        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], 'id, seed', IGNORE_MISSING);
        if (!$run) {
            return 0;
        }
        $ids = $DB->get_fieldset_sql(
            "SELECT ru.id
               FROM {local_groupdist_run_user} ru
               JOIN {groups_members} gm ON gm.groupid = ru.groupid AND gm.userid = ru.userid
              WHERE ru.runid = :runid
                AND ru.writestatus = :planned
                AND ru.groupid <> 0
                AND gm.component = :component
                AND gm.itemid = :seed",
            [
                'runid' => $runid,
                'planned' => self::WRITE_PLANNED,
                'component' => 'local_groupdist',
                'seed' => (int) $run->seed,
            ]
        );
        foreach (array_chunk($ids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'ru');
            $DB->set_field_select('local_groupdist_run_user', 'writestatus', self::WRITE_WRITTEN, "id {$insql}", $params);
        }

        $written = $DB->count_records('local_groupdist_run_user', ['runid' => $runid, 'writestatus' => self::WRITE_WRITTEN]);
        $DB->update_record('local_groupdist_run', (object) [
            'id' => $runid,
            'status' => self::STATUS_ABORTED,
            'memberswritten' => $written,
            'timecompleted' => time(),
        ]);
        return $written;
    }

    /**
     * Whether a distribution with this seed has already been applied to the course.
     *
     * Only a completed run counts. apply.php refuses every spent seed
     * ({@see self::is_seed_spent()}) and asks this only to tell a replay of a
     * completed apply from a seed that wrote only part of its plan.
     *
     * @param int $courseid The course id.
     * @param int $seed The distribution seed.
     * @return bool True when a completed run exists for that course and seed.
     */
    public static function is_applied(int $courseid, int $seed): bool {
        global $DB;

        return $DB->record_exists('local_groupdist_run', [
            'courseid' => $courseid,
            'seed' => $seed,
            'status' => self::STATUS_COMPLETED,
        ]);
    }

    /**
     * Whether a seed is spent: a run under it finished, or memberships carry its stamp.
     *
     * Every recompute hides the memberships stamped with its own seed, which is
     * what lets an interrupted run resume with its original plan
     * ({@see distribution::build()}). Once a run has written, that same
     * invisibility makes a new plan under the seed treat those participants as
     * ungrouped, so a changed plan can add one of them to a second group. A
     * spent seed must therefore never start another plan, nor be applied again.
     *
     * Spent means a completed or partial run, an aborted one whose earlier
     * attempt wrote memberships, or any membership in the course stamped with
     * the seed (component local_groupdist, itemid = the seed). The stamp is
     * the only sign that a pending run wrote: an interrupted inline apply, or
     * a background task that died, leaves memberswritten at 0 until complete()
     * or abort() seals the run.
     *
     * This decides the seed of a new plan and whether a POST may apply. A
     * resumable background run has stamped rows under its own seed by design,
     * so its recompute must not consult it.
     *
     * @param int $courseid The course id.
     * @param int $seed The distribution seed.
     * @return bool True when the seed is spent in that course.
     */
    public static function is_seed_spent(int $courseid, int $seed): bool {
        global $DB;

        $finished = $DB->record_exists_select(
            'local_groupdist_run',
            'courseid = :courseid AND seed = :seed
             AND (status IN (:completed, :partial) OR (status = :aborted AND memberswritten > 0))',
            [
                'courseid' => $courseid,
                'seed' => $seed,
                'completed' => self::STATUS_COMPLETED,
                'partial' => self::STATUS_PARTIAL,
                'aborted' => self::STATUS_ABORTED,
            ]
        );
        if ($finished) {
            return true;
        }
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {groups_members} gm
               JOIN {groups} g ON g.id = gm.groupid
              WHERE g.courseid = :courseid
                AND gm.component = :component
                AND gm.itemid = :seed",
            ['courseid' => $courseid, 'component' => 'local_groupdist', 'seed' => $seed]
        );
    }

    /**
     * Delete every run of a course, on course deletion.
     *
     * The recycle bin keeps a backup file, not the course, so the rows would be
     * unreachable orphans: a restore creates a new course, and the audit travels
     * in the backup only when course logs are included.
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
