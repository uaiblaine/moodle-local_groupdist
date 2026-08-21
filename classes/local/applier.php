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
 * Writes a computed distribution into core group memberships.
 *
 * Every membership goes through groups_add_member() — bypassing it would skip
 * the group_member_added event, the membership hooks and the cache
 * invalidations other plugins rely on (core restore refuses the same shortcut).
 * Writes run in chunked transactions with a per-member exception guard: the
 * unique (userid, groupid) key can race a teacher adding the same user by hand,
 * and that duplicate must not kill the whole chunk.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class applier {
    /** @var int Memberships at or below this run inline in the web request. */
    public const INLINE_LIMIT = 500;

    /** @var int Memberships per transaction chunk. */
    public const CHUNK_SIZE = 100;

    /**
     * Apply a distribution.
     *
     * @param distribution $distribution The computed distribution.
     * @param \core\progress\base|null $progress Optional progress consumer
     *   (one tick per chunk).
     * @param int $runid The audit run id (created by runlog::create() before
     *   applying); rides on the distribution_applied event.
     * @return array Summary: 'added' (memberships written or already present),
     *   'failed' (rejected by core — deleted/unenrolled meanwhile) and
     *   'failedpairs' (list of [groupid, userid] for the audit log).
     */
    public static function apply(distribution $distribution, ?\core\progress\base $progress, int $runid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $groupids = array_keys($distribution->allocation->assignments);
        $grouprecords = $groupids ? $DB->get_records_list('groups', 'id', $groupids) : [];

        // Flatten to (group, user) pairs in deterministic order.
        $pairs = [];
        foreach ($distribution->allocation->assignments as $groupid => $userids) {
            if (!isset($grouprecords[$groupid])) {
                continue;
            }
            foreach ($userids as $userid) {
                if (isset($distribution->users[$userid])) {
                    $pairs[] = [$grouprecords[$groupid], $distribution->users[$userid]];
                }
            }
        }

        $added = 0;
        $failed = 0;
        $failedpairs = [];
        $chunks = array_chunk($pairs, self::CHUNK_SIZE);
        if ($progress) {
            $progress->start_progress(get_string('applyprogress', 'local_groupdist'), count($chunks));
        }
        $itemid = $distribution->options->seed;
        foreach ($chunks as $chunk) {
            /* A dml_write_exception inside a transaction poisons it on
               PostgreSQL (every later statement fails until rollback), so a
               per-member catch cannot live inside the transaction: on a race
               with a concurrent manual add, roll the chunk back and replay it
               member by member without a transaction. */
            $chunkadded = 0;
            $chunkfailed = 0;
            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($chunk as [$group, $user]) {
                    if (groups_add_member($group, $user, 'local_groupdist', $itemid)) {
                        $chunkadded++;
                    } else {
                        $chunkfailed++;
                        $failedpairs[] = [(int) $group->id, (int) $user->id];
                    }
                }
                $transaction->allow_commit();
            } catch (\dml_write_exception $e) {
                try {
                    $transaction->rollback($e);
                } catch (\dml_write_exception $ignored) {
                    // Rollback rethrows; the replay below recovers.
                    $ignored = null;
                }
                $chunkadded = 0;
                $chunkfailed = 0;
                foreach ($chunk as [$group, $user]) {
                    try {
                        if (groups_add_member($group, $user, 'local_groupdist', $itemid)) {
                            $chunkadded++;
                        } else {
                            $chunkfailed++;
                            $failedpairs[] = [(int) $group->id, (int) $user->id];
                        }
                    } catch (\dml_write_exception $memberexception) {
                        /* dml_write_exception covers duplicate keys AND genuine
                           failures (deadlock victim, lock timeout) — they are
                           indistinguishable by type, so ask the table.

                           Ask it DIRECTLY. groups_is_member() is
                           visibility-filtered: it short-circuits to a plain
                           record_exists() only when can_view_all_groups() is
                           true, and otherwise admits a row for another user
                           only when the group's visibility is ALL, or MEMBERS
                           with the viewer a member too. For a group that is
                           OWN or NONE it answers false for a row that exists,
                           and the membership would be counted as failed and
                           logged as such. This is a write-side integrity
                           check, not a display decision — visibility
                           filtering is simply the wrong question here. */
                        $landed = $DB->record_exists(
                            'groups_members',
                            ['groupid' => (int) $group->id, 'userid' => (int) $user->id]
                        );
                        if ($landed) {
                            $chunkadded++;
                        } else {
                            $chunkfailed++;
                            $failedpairs[] = [(int) $group->id, (int) $user->id];
                        }
                    }
                }
            }
            $added += $chunkadded;
            $failed += $chunkfailed;
            if ($progress) {
                $progress->increment_progress();
            }
        }
        if ($progress) {
            $progress->end_progress();
        }

        $context = \core\context\course::instance($distribution->options->courseid);
        \local_groupdist\event\distribution_applied::create([
            'context' => $context,
            'objectid' => $runid,
            'other' => [
                'seed' => $distribution->options->seed,
                'groupcount' => count($groupids),
                'memberships' => $added,
            ],
        ])->trigger();

        return ['added' => $added, 'failed' => $failed, 'failedpairs' => $failedpairs];
    }
}
