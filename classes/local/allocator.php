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
 * Pure, deterministic distribution engine.
 *
 * No database access: candidates, capacities and existing memberships come in
 * as plain arrays, the result goes out as an {@see allocation}. Given the same
 * inputs and seed the output is identical, which is what lets the preview web
 * service and the (possibly minutes-later) apply step recompute the same plan
 * instead of persisting it.
 *
 * Affinity limitation, by design: the together/apart constraints are evaluated
 * among the users being distributed in this run; profile values of members a
 * group already has are not considered.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class allocator {
    /** @var string Warning: users left without a group (all capacity exhausted). */
    public const WARNING_UNASSIGNED = 'unassigned';

    /** @var string Warning: an affinity bucket did not fit one group and was split. */
    public const WARNING_SPLIT = 'affinitysplit';

    /** @var string Warning: keep-apart impossible for a value (more holders than groups). */
    public const WARNING_APART = 'apartinfeasible';

    /** @var string Warning: users without a value in the affinity field. */
    public const WARNING_NOVALUE = 'novalue';

    /**
     * Run the allocation.
     *
     * @param array $userids Candidate user ids, already in the requested order
     *   (the seeded shuffle for the random order happens here).
     * @param array $affinity Map of userid => affinity value (null/'' = no value);
     *   ignored when the options carry no affinity rule.
     * @param array $groups Ordered list of arrays with keys: 'id' (int),
     *   'capacity' (int|null, null = unlimited), 'current' (int),
     *   'existing' (map of userid => true for current members).
     * @param options $options The distribution options (allocateby, affinity, seed).
     * @return allocation The computed assignment plus typed warnings.
     */
    public static function allocate(array $userids, array $affinity, array $groups, options $options): allocation {
        $result = new allocation();
        $state = [];
        foreach ($groups as $group) {
            $capacity = $group['capacity'];
            $state[] = [
                'id' => (int) $group['id'],
                'remaining' => ($capacity === null) ? PHP_INT_MAX : max(0, (int) $capacity),
                'final' => (int) $group['current'],
                'existing' => $group['existing'] ?? [],
                'values' => [],
                'assigned' => [],
            ];
            $result->assignments[(int) $group['id']] = [];
        }

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if ($options->allocateby === options::ALLOCATE_RANDOM) {
            $userids = self::seeded_shuffle($userids, $options->seed);
        }

        if (!$state || !$userids) {
            return $result;
        }

        if ($options->get_affinity_source() === '') {
            self::fill_balanced($state, $userids, $result);
        } else if ($options->get_affinity_mode() === options::AFFINITY_TOGETHER) {
            self::fill_together($state, $userids, $affinity, $result);
        } else {
            self::fill_apart($state, $userids, $affinity, $result);
        }

        foreach ($state as $groupstate) {
            $result->assignments[$groupstate['id']] = $groupstate['assigned'];
        }
        if ($result->unassigned) {
            $result->warnings[] = ['type' => self::WARNING_UNASSIGNED, 'count' => count($result->unassigned)];
        }
        return $result;
    }

    /**
     * No-affinity fill: quotas that equalise final group sizes, then contiguous chunks.
     *
     * Quotas are simulated first (greedy: next user to the group with the
     * smallest final size that still has capacity), then the ordered user list
     * is sliced sequentially — so alphabetical orders produce contiguous runs
     * per group, like core autogroup.
     *
     * When candidates may already belong to a target group (ignore-grouped
     * off), contiguous slicing would hand members back to their own group —
     * inflating the preview and wasting capacity on writes that are no-ops.
     * That case falls back to per-user placement, which honours the existing
     * membership sets at the cost of contiguity.
     *
     * @param array $state Group state, modified in place.
     * @param array $userids Ordered candidate ids.
     * @param allocation $result Receives unassigned users.
     * @return void
     */
    private static function fill_balanced(array &$state, array $userids, allocation $result): void {
        foreach ($state as $groupstate) {
            if ($groupstate['existing']) {
                self::fill_individuals($state, $userids, $result);
                return;
            }
        }

        $quotas = array_fill(0, count($state), 0);
        $placed = 0;
        $total = count($userids);
        while ($placed < $total) {
            $index = self::pick_min_final($state);
            if ($index === null) {
                break;
            }
            $quotas[$index]++;
            $state[$index]['remaining']--;
            $state[$index]['final']++;
            $placed++;
        }

        $offset = 0;
        foreach ($quotas as $index => $quota) {
            if ($quota > 0) {
                $state[$index]['assigned'] = array_slice($userids, $offset, $quota);
                $offset += $quota;
            }
        }
        $result->unassigned = array_slice($userids, $offset);
    }

    /**
     * Keep-together fill: bucket users by value, bin-pack buckets, largest first.
     *
     * A bucket that does not fit the emptiest group is split across groups with
     * a warning. Users already member of the chosen group are dropped from the
     * bucket silently — they already sit with their value peers.
     *
     * @param array $state Group state, modified in place.
     * @param array $userids Ordered candidate ids.
     * @param array $affinity Map of userid => affinity value.
     * @param allocation $result Receives unassigned users and warnings.
     * @return void
     */
    private static function fill_together(array &$state, array $userids, array $affinity, allocation $result): void {
        [$buckets, $novalue] = self::bucketise($userids, $affinity);

        foreach ($buckets as $bucket) {
            $remainder = $bucket['members'];
            $parts = 0;
            while ($remainder) {
                $index = self::pick_max_remaining($state);
                if ($index === null) {
                    $result->unassigned = array_merge($result->unassigned, $remainder);
                    break;
                }
                $take = min(count($remainder), $state[$index]['remaining']);
                $chunk = [];
                $rest = [];
                foreach ($remainder as $userid) {
                    if (isset($state[$index]['existing'][$userid])) {
                        // Already a member of this group: nothing to do for them.
                        continue;
                    }
                    if (count($chunk) < $take) {
                        $chunk[] = $userid;
                    } else {
                        $rest[] = $userid;
                    }
                }
                self::assign_many($state[$index], $chunk);
                $remainder = $rest;
                $parts++;
            }
            if ($parts > 1) {
                $result->warnings[] = ['type' => self::WARNING_SPLIT, 'value' => $bucket['value'], 'count' => $parts];
            }
        }

        self::fill_individuals($state, $novalue, $result);
        if ($novalue) {
            $result->warnings[] = ['type' => self::WARNING_NOVALUE, 'count' => count($novalue)];
        }
    }

    /**
     * Keep-apart fill: spread each value's holders over distinct groups.
     *
     * When a value has more holders than there are groups with capacity, the
     * surplus is still placed (capacity permitting) and reported as infeasible
     * for that value — the pigeonhole makes a clean solution impossible.
     *
     * @param array $state Group state, modified in place.
     * @param array $userids Ordered candidate ids.
     * @param array $affinity Map of userid => affinity value.
     * @param allocation $result Receives unassigned users and warnings.
     * @return void
     */
    private static function fill_apart(array &$state, array $userids, array $affinity, allocation $result): void {
        [$buckets, $novalue] = self::bucketise($userids, $affinity);
        $violations = [];

        foreach ($buckets as $bucket) {
            $value = $bucket['value'];
            foreach ($bucket['members'] as $userid) {
                $fresh = function (array $groupstate) use ($value, $userid): bool {
                    return !isset($groupstate['values'][$value]) && !isset($groupstate['existing'][$userid]);
                };
                $index = self::pick_min_final($state, $fresh);
                if ($index === null) {
                    // Every group with capacity already holds this value: violation.
                    $any = function (array $groupstate) use ($userid): bool {
                        return !isset($groupstate['existing'][$userid]);
                    };
                    $index = self::pick_min_final($state, $any);
                    if ($index === null) {
                        $result->unassigned[] = $userid;
                        continue;
                    }
                    $violations[$value] = ($violations[$value] ?? 0) + 1;
                }
                self::assign_many($state[$index], [$userid]);
                $state[$index]['values'][$value] = true;
            }
        }

        foreach ($violations as $value => $count) {
            $result->warnings[] = ['type' => self::WARNING_APART, 'value' => (string) $value, 'count' => $count];
        }

        self::fill_individuals($state, $novalue, $result);
        if ($novalue) {
            $result->warnings[] = ['type' => self::WARNING_NOVALUE, 'count' => count($novalue)];
        }
    }

    /**
     * Place unconstrained users one by one onto the emptiest group with capacity.
     *
     * @param array $state Group state, modified in place.
     * @param array $userids User ids to place.
     * @param allocation $result Receives unassigned users.
     * @return void
     */
    private static function fill_individuals(array &$state, array $userids, allocation $result): void {
        foreach ($userids as $userid) {
            $notmember = function (array $groupstate) use ($userid): bool {
                return !isset($groupstate['existing'][$userid]);
            };
            $index = self::pick_min_final($state, $notmember);
            if ($index === null) {
                $result->unassigned[] = $userid;
                continue;
            }
            self::assign_many($state[$index], [$userid]);
        }
    }

    /**
     * Split candidates into value buckets (largest first, then value order) and a no-value pool.
     *
     * @param array $userids Ordered candidate ids.
     * @param array $affinity Map of userid => affinity value.
     * @return array Two elements: list of ['value' => string, 'members' => int[]], and int[] of no-value users.
     */
    private static function bucketise(array $userids, array $affinity): array {
        $map = [];
        $novalue = [];
        foreach ($userids as $userid) {
            $value = trim((string) ($affinity[$userid] ?? ''));
            if ($value === '') {
                $novalue[] = $userid;
            } else {
                $map[$value][] = $userid;
            }
        }

        $buckets = [];
        foreach ($map as $value => $members) {
            $buckets[] = ['value' => (string) $value, 'members' => $members];
        }
        usort($buckets, function (array $a, array $b): int {
            $bysize = count($b['members']) <=> count($a['members']);
            return $bysize !== 0 ? $bysize : strcmp($a['value'], $b['value']);
        });
        return [$buckets, $novalue];
    }

    /**
     * Append users to a group's assignment, updating its counters.
     *
     * @param array $groupstate One group's state entry, modified in place.
     * @param array $userids Users to add.
     * @return void
     */
    private static function assign_many(array &$groupstate, array $userids): void {
        foreach ($userids as $userid) {
            $groupstate['assigned'][] = $userid;
            $groupstate['final']++;
            $groupstate['remaining']--;
        }
    }

    /**
     * Pick the group with the smallest final size among those with capacity left.
     *
     * @param array $state Group state.
     * @param callable|null $suitable Optional extra predicate on a group state entry.
     * @return int|null The state index, or null when no group qualifies.
     */
    private static function pick_min_final(array $state, ?callable $suitable = null): ?int {
        $best = null;
        foreach ($state as $index => $groupstate) {
            if ($groupstate['remaining'] <= 0) {
                continue;
            }
            if ($suitable !== null && !$suitable($groupstate)) {
                continue;
            }
            if ($best === null || $groupstate['final'] < $state[$best]['final']) {
                $best = $index;
            }
        }
        return $best;
    }

    /**
     * Pick the group with the most remaining capacity (ties: smaller final size, then order).
     *
     * @param array $state Group state.
     * @return int|null The state index, or null when every group is full.
     */
    private static function pick_max_remaining(array $state): ?int {
        $best = null;
        foreach ($state as $index => $groupstate) {
            if ($groupstate['remaining'] <= 0) {
                continue;
            }
            $moreroom = $best !== null && $groupstate['remaining'] > $state[$best]['remaining'];
            $sameroomsmaller = $best !== null
                && $groupstate['remaining'] === $state[$best]['remaining']
                && $groupstate['final'] < $state[$best]['final'];
            if ($best === null || $moreroom || $sameroomsmaller) {
                $best = $index;
            }
        }
        return $best;
    }

    /**
     * Deterministic Fisher-Yates shuffle.
     *
     * Seeds the global Mersenne Twister; acceptable here because the whole
     * request path (preview web service call or apply run) computes exactly one
     * allocation.
     *
     * @param array $items The items to shuffle.
     * @param int $seed The seed.
     * @return array The shuffled items.
     */
    private static function seeded_shuffle(array $items, int $seed): array {
        mt_srand($seed);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }
}
