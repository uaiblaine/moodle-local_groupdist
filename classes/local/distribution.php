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
        if ($options->affinityfield !== '') {
            foreach ($distribution->users as $user) {
                $affinity[(int) $user->id] = $user->affinity;
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
     * Fingerprint of everything the plan depends on besides the options.
     *
     * Covers, in fetch order, every input the allocator's output is a function
     * of: each candidate's id, sort keys (name, idnumber — they drive the
     * ordering, including the seeded shuffle's input permutation) and affinity
     * value, plus each group's id, declared seats, current member count and
     * existing-member set. Any concurrent change to one of these shifts the
     * fingerprint and the apply step refuses to write a plan the teacher never
     * saw.
     *
     * @return string The sha256 fingerprint.
     */
    private function compute_fingerprint(): string {
        $userparts = [];
        foreach ($this->users as $user) {
            $userparts[] = [
                (int) $user->id,
                (string) ($user->lastname ?? ''),
                (string) ($user->firstname ?? ''),
                (string) ($user->idnumber ?? ''),
                trim((string) ($user->affinity ?? '')),
            ];
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
