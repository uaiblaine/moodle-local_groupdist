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

    /** @var string No-op: everyone already belongs to the group the plan chose for them. */
    public const NOOP_ALLPLACED = 'allplaced';

    /** @var options The options this distribution was computed from. */
    public options $options;

    /** @var array Ordered candidate records keyed by user id (id, name fields, affinity). */
    public array $users = [];

    /**
     * @var array Ordered group entries: arrays with 'id', 'name', 'seats' (?int),
     *   'location' (?string), 'current' (int), 'capacity' (?int, null = unlimited).
     */
    public array $groups = [];

    /** @var allocation The computed assignment. */
    public allocation $allocation;

    /** @var array Typed warnings (allocator warnings plus builder warnings). */
    public array $warnings = [];

    /** @var string Fingerprint of candidates + group capacities, checked again at apply time. */
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
        $coursegroups = groups_get_all_groups($options->courseid);
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

        /* Existing membership sets are only needed to steer the allocator when
           candidates may already belong to a selected group; with the default
           "ignore grouped" filter those users never become candidates. */
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
            // Each membership write then triggers a communication-room sync with
            // a full course-roster query — large applies get slow.
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
        $distribution->fingerprint = $distribution->compute_fingerprint();
        return $distribution;
    }

    /**
     * Aggregate numbers for the preview header.
     *
     * @return array Keys: candidates, groups, memberships, unassigned, seatstotal
     *   (sum of declared seats, -1 when none declared), overbooked (memberships
     *   beyond declared seats).
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
     * ordered outermost first, so every no-op falls into exactly one of them
     * and a state added later cannot land back in the silence this method
     * exists to remove.
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
           every one of them already sits in the group the plan chose. That is
           a third outcome beside "placed" and "unplaced", not a miscount —
           the allocator skips a member it would only re-add. */
        return self::NOOP_ALLPLACED;
    }

    /**
     * The teacher-facing explanation of a no-op run.
     *
     * A literal match, never a composed string id: the fleet rule bans
     * get_string() on a built key. The keep-grouped hint is appended rather
     * than folded in because it states that a filter is switched on, which is
     * a fact, and not that the filter is the cause, which would need a probe.
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
     * @return string The sha256 fingerprint.
     */
    private function compute_fingerprint(): string {
        $rulecount = $this->options->affinityrules->count();
        $userparts = [];
        foreach ($this->users as $user) {
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
        foreach ($this->groups as $group) {
            $existing = array_map('intval', array_keys($group['existing']));
            sort($existing);
            $groupparts[] = [$group['id'], $group['seats'], $group['current'], $existing];
        }
        return hash('sha256', json_encode([$userparts, $groupparts]));
    }
}
