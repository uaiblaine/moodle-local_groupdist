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
 * One fully computed distribution: candidates + group data + allocation.
 *
 * Built identically by the preview web service (every page call) and by the
 * apply step — determinism comes from the seed inside the options, and the
 * fingerprint detects when the world changed in between.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class distribution {
    /** @var string Warning: seats mode is on but some groups declare no seats value. */
    public const WARNING_NOSEATS = 'noseats';

    /** @var string Warning: communication subsystem makes each membership write expensive. */
    public const WARNING_COMMSLOW = 'commslow';

    /** @var string No-op: every selected group has been deleted since the options were posted. */
    public const NOOP_NOGROUPS = 'nogroups';

    /** @var string No-op: no course participant matches the member filters. */
    public const NOOP_NOCANDIDATES = 'nocandidates';

    /** @var string No-op: participants match, but no group can take any of them. */
    public const NOOP_NOROOM = 'noroom';

    /** @var string No-op: every candidate already belongs to a selected group. */
    public const NOOP_ALLPLACED = 'allplaced';

    /** @var options The options this distribution was computed from. */
    public options $options;

    /** @var array Ordered candidate records keyed by user id (id, name fields, affinity). */
    public array $users = [];

    /**
     * @var array Ordered group entries: arrays with 'id', 'name', 'seats' (?int),
     *   'location' (?string), 'current' (int), 'capacity' (?int, null = unlimited),
     *   'existing' (map of userid => true; filled only when ignoregrouped is off).
     */
    public array $groups = [];

    /** @var allocation The computed assignment. */
    public allocation $allocation;

    /** @var array Typed warnings (allocator warnings plus builder warnings). */
    public array $warnings = [];

    /** @var string Fingerprint of the allocator's inputs (see compute_fingerprint()), checked again at apply time. */
    public string $fingerprint = '';

    /**
     * Compute a distribution.
     *
     * @param options $options The validated options; groupids not belonging to
     *   the course are dropped.
     * @param \core\context\course $context The course context.
     * @return self The computed distribution.
     */
    public static function build(options $options, \core\context\course $context): self {
        global $DB;

        $distribution = new self();
        $distribution->options = $options;

        // Resolve and order the target groups (name, then id — stable between runs).
        $coursegroups = self::get_destination_groups($context);
        $selected = [];
        foreach ($options->groupids as $groupid) {
            if (isset($coursegroups[$groupid])) {
                $selected[$groupid] = $coursegroups[$groupid];
            }
        }
        usort($selected, function (\stdClass $a, \stdClass $b): int {
            return strcmp($a->name, $b->name) ?: ($a->id <=> $b->id);
        });

        $groupids = array_map(function (\stdClass $group): int {
            return (int) $group->id;
        }, $selected);
        $values = fields::get_group_values($groupids);
        // This run's own partial writes (component + seed as itemid) stay
        // invisible everywhere in the recompute, so an interrupted background
        // apply resumes with the identical plan (see fields::get_member_counts).
        $counts = fields::get_member_counts($groupids, $options->seed);

        /* Existing membership sets tell the allocator which candidates already
           belong to a selected group (they stay there, and their values bind
           the others); with the default "ignore grouped" filter those users
           never become candidates. */
        $existing = array_fill_keys($groupids, []);
        if (!$options->ignoregrouped && $groupids) {
            [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'g');
            $members = $DB->get_recordset_select(
                'groups_members',
                "groupid {$insql} AND (component <> 'local_groupdist' OR itemid <> :exseed)",
                $params + ['exseed' => $options->seed],
                '',
                'id, groupid, userid'
            );
            foreach ($members as $member) {
                $existing[(int) $member->groupid][(int) $member->userid] = true;
            }
            $members->close();
        }

        $noseats = 0;
        foreach ($selected as $group) {
            $groupid = (int) $group->id;
            $seats = $values[$groupid]->seats;
            $current = $counts[$groupid];
            $capacity = null;
            if ($options->useseats) {
                if ($seats === null) {
                    $noseats++;
                } else {
                    $capacity = max(0, $seats + $options->overbook - $current);
                }
            }
            $distribution->groups[] = [
                'id' => $groupid,
                'name' => $group->name,
                'seats' => $seats,
                'location' => $values[$groupid]->location,
                'current' => $current,
                'capacity' => $capacity,
                'existing' => $existing[$groupid],
            ];
        }
        if ($noseats > 0) {
            $distribution->warnings[] = ['type' => self::WARNING_NOSEATS, 'count' => $noseats];
        }

        global $CFG;
        if (!empty($CFG->enablecommunicationsubsystem)) {
            // In a course whose communication runs in group mode, each membership
            // write syncs the group room with a full course-roster query.
            $distribution->warnings[] = ['type' => self::WARNING_COMMSLOW, 'count' => 0];
        }

        $distribution->users = candidates::fetch($options, $context);

        $affinity = [];
        foreach (array_keys($options->affinityrules->get_rules()) as $i) {
            $affinity[$i] = [];
            foreach ($distribution->users as $user) {
                $affinity[$i][(int) $user->id] = $user->{'affinity' . $i} ?? null;
            }
        }

        $distribution->allocation = allocator::allocate(
            array_keys($distribution->users),
            $affinity,
            $distribution->groups,
            $options
        );
        $distribution->warnings = array_merge($distribution->warnings, $distribution->allocation->warnings);
        $distribution->fingerprint = self::compute_fingerprint($distribution);
        return $distribution;
    }

    /**
     * The course groups the acting user may distribute into, keyed by id.
     *
     * Every entry point resolves submitted destination ids against this set
     * (distribute.php, get_preview, apply.php and build() itself), so it must
     * be the same set on every call or the fingerprint turns the difference
     * into a spurious "stale" refusal. The bulk edit page and save_group_fields
     * use it too, to limit which groups a user can see and edit.
     *
     * A viewhiddengroups holder gets every group; anyone else gets ALL groups
     * plus the MEMBERS groups they belong to. That is the rule
     * groups_get_all_groups() applies in its SQL path minus OWN groups, which
     * that helper admits for a member: core shows a member of an OWN group
     * only their own row, while the preview lists a destination's existing
     * members and counts them. NONE and OWN groups are therefore distributed
     * into only by a holder.
     *
     * The rule is stated here rather than delegated: groups_get_all_groups()
     * reads core/coursehiddengroups to decide whether to filter at all, and on
     * a cold cache that check reports "nothing hidden", so one call returns
     * every group, NONE included ({@see profilefields::get_source_groups()}
     * explains the mechanism).
     *
     * @param \core\context\course $context The course context.
     * @return array Group records (every {groups} column) keyed by id, ordered by name, then id.
     */
    public static function get_destination_groups(\core\context\course $context): array {
        global $DB, $USER;

        $params = ['courseid' => (int) $context->instanceid];
        $visibility = '';
        if (!has_capability('moodle/course:viewhiddengroups', $context)) {
            $visibility = "AND (g.visibility = :all
                                OR (g.visibility = :members
                                    AND EXISTS (SELECT 1
                                                  FROM {groups_members} gm
                                                 WHERE gm.groupid = g.id AND gm.userid = :userid)))";
            $params += [
                'all' => GROUPS_VISIBILITY_ALL,
                'members' => GROUPS_VISIBILITY_MEMBERS,
                'userid' => (int) $USER->id,
            ];
        }
        return $DB->get_records_sql(
            "SELECT g.*
               FROM {groups} g
              WHERE g.courseid = :courseid {$visibility}
           ORDER BY g.name, g.id",
            $params
        );
    }

    /**
     * Aggregate numbers for the preview header.
     *
     * @return array Keys: candidates, groups, memberships, unassigned, seatstotal
     *   (sum of declared seats, -1 when none declared), overbooked (memberships
     *   beyond declared seats; 0 when seats are not used as capacity).
     */
    public function totals(): array {
        $seatstotal = -1;
        $overbooked = 0;
        $allocated = [];
        foreach ($this->allocation->assignments as $groupid => $userids) {
            $allocated[$groupid] = count($userids);
        }
        foreach ($this->groups as $group) {
            if ($group['seats'] !== null) {
                $seatstotal = ($seatstotal === -1) ? 0 : $seatstotal;
                $seatstotal += $group['seats'];
                $total = $group['current'] + ($allocated[$group['id']] ?? 0);
                $overbooked += max(0, $total - $group['seats']);
            }
        }
        return [
            'candidates' => count($this->users),
            'groups' => count($this->groups),
            'memberships' => $this->allocation->count_memberships(),
            'unassigned' => count($this->allocation->unassigned),
            'seatstotal' => $seatstotal,
            'overbooked' => $this->options->useseats ? $overbooked : 0,
        ];
    }

    /**
     * Why this run would write nothing, or '' when it would write something.
     *
     * Keyed on memberships === 0 rather than on an empty candidate list,
     * because that is the condition the preview's Apply button is disabled by
     * and there is more than one way to reach it. The arms are exhaustive and
     * ordered outermost first, so every no-op gets exactly one reason.
     *
     * Display only: nothing here feeds compute_fingerprint(), which must stay
     * a function of the allocator's inputs alone.
     *
     * @return string One of the NOOP_* constants, or '' when memberships > 0.
     */
    public function noop_reason(): string {
        if ($this->allocation->count_memberships() > 0) {
            return '';
        }
        if (!$this->groups) {
            // The web service re-intersects the selection against the course's
            // live groups on every call, so a deletion mid-preview lands here.
            return self::NOOP_NOGROUPS;
        }
        if (!$this->users) {
            return self::NOOP_NOCANDIDATES;
        }
        if ($this->allocation->unassigned) {
            return self::NOOP_NOROOM;
        }
        /* Candidates, groups, nobody unassigned and still nothing to write:
           every one of them already sits in a selected group, where the
           allocator keeps them. */
        return self::NOOP_ALLPLACED;
    }

    /**
     * The teacher-facing explanation of a no-op run.
     *
     * Each reason maps to a literal string id, never a composed one. The
     * ignore-grouped hint is appended rather than folded in because it states
     * that the filter is switched on, not that the filter is the cause, which
     * would need a probe.
     *
     * @return string The localised message, or '' when the run would write.
     */
    public function noop_message(): string {
        $reason = $this->noop_reason();
        $message = match ($reason) {
            self::NOOP_NOGROUPS => get_string('noopnogroups', 'local_groupdist'),
            self::NOOP_NOCANDIDATES => get_string('noopnocandidates', 'local_groupdist'),
            self::NOOP_NOROOM => get_string('noopnoroom', 'local_groupdist'),
            self::NOOP_ALLPLACED => get_string('noopallplaced', 'local_groupdist'),
            default => '',
        };
        if ($reason === self::NOOP_NOCANDIDATES && $this->options->ignoregrouped) {
            $message .= ' ' . get_string('noophintignoregrouped', 'local_groupdist');
        }
        return $message;
    }

    /**
     * Fingerprint of everything the plan depends on besides the options.
     *
     * Covers, in fetch order, every input the allocator's output is a function
     * of: each candidate's id, sort keys (name, idnumber — they drive the
     * ordering, including the seeded shuffle's input permutation) and one
     * affinity value per rule in rule order, plus each group's id, declared
     * seats, current member count and existing-member set. Any concurrent
     * change to one of these shifts the fingerprint and the apply step refuses
     * to write a plan the teacher never saw.
     *
     * Static and called as self::compute_fingerprint() because phpmd's
     * UnusedPrivateMethod rule does not resolve a call made on a local variable
     * of the class, which is what this static factory would otherwise write.
     * It is not dead code: every build() runs it, and removing it would
     * silently disable both the staleness check and resumable applies.
     *
     * @param self $distribution The fully built distribution to fingerprint.
     * @return string The sha256 fingerprint.
     */
    private static function compute_fingerprint(self $distribution): string {
        $rulecount = $distribution->options->affinityrules->count();
        $userparts = [];
        foreach ($distribution->users as $user) {
            $part = [
                (int) $user->id,
                (string) ($user->lastname ?? ''),
                (string) ($user->firstname ?? ''),
                (string) ($user->idnumber ?? ''),
            ];
            for ($i = 0; $i < $rulecount; $i++) {
                $part[] = trim((string) ($user->{'affinity' . $i} ?? ''));
            }
            $userparts[] = $part;
        }
        $groupparts = [];
        foreach ($distribution->groups as $group) {
            $existing = array_map('intval', array_keys($group['existing']));
            sort($existing);
            $groupparts[] = [$group['id'], $group['seats'], $group['current'], $existing];
        }
        return hash('sha256', json_encode([$userparts, $groupparts]));
    }
}
