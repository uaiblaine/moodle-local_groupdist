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
 * Multi-rule semantics (all rules always apply — an implicit AND):
 * keep-together rules jointly cluster users by the composite tuple of their
 * values; keep-apart rules each add "no two users sharing a value in one
 * group", enforced simultaneously via per-group held-value sets keyed by
 * (rule, value). List position is the priority: when a pair is bound together
 * and separated at once, the rule closer to the top of the list prevails and
 * the loser's violations are counted; when no group satisfies every apart
 * rule, violations are taken lexicographically — the lowest-priority rule
 * yields first. Capacity stays the only hard constraint.
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

    /** @var string Warning: an affinity cluster did not fit one group and was split. */
    public const WARNING_SPLIT = 'affinitysplit';

    /** @var string Warning: keep-apart impossible for a (rule, value) — violations counted. */
    public const WARNING_APART = 'apartinfeasible';

    /** @var string Warning: users without a value in a rule's source. */
    public const WARNING_NOVALUE = 'novalue';

    /** @var string Warning: together and apart rules bound the same pair; priority decided. */
    public const WARNING_CONTRADICTION = 'affinitycontradiction';

    /** @var string Separator joining composite key components (never appears in trimmed values). */
    private const KEY_SEPARATOR = "\x1F";

    /**
     * Run the allocation.
     *
     * @param array $userids Candidate user ids, already in the requested order
     *   (the seeded shuffle for the random order happens here).
     * @param array $affinity List of per-rule value maps, index-aligned with
     *   the options ruleset: [ruleindex => [userid => value]]; null/'' = no
     *   value. Ignored when the options carry no affinity rule.
     * @param array $groups Ordered list of arrays with keys: 'id' (int),
     *   'capacity' (int|null, null = unlimited), 'current' (int),
     *   'existing' (map of userid => true for current members).
     * @param options $options The distribution options (allocateby, ruleset, seed).
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

        if ($options->affinityrules->is_empty()) {
            self::fill_balanced($state, $userids, $result);
        } else {
            self::fill_rules($state, $userids, $affinity, $options->affinityrules->get_rules(), $result);
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
     * Multi-rule fill: cluster by composite together-keys, spread apart-values.
     *
     * @param array $state Group state, modified in place.
     * @param array $userids Ordered candidate ids.
     * @param array $affinity Per-rule value maps ([ruleindex => [userid => value]]).
     * @param array $rules The ruleset entries ('source' and 'mode'), position = priority.
     * @param allocation $result Receives unassigned users and warnings.
     * @return void
     */
    private static function fill_rules(
        array &$state,
        array $userids,
        array $affinity,
        array $rules,
        allocation $result
    ): void {
        $val = function (int $ruleindex, int $userid) use ($affinity): string {
            return trim((string) ($affinity[$ruleindex][$userid] ?? ''));
        };

        $togetheridx = [];
        $apartidx = [];
        foreach ($rules as $i => $rule) {
            if ($rule['mode'] === options::AFFINITY_TOGETHER) {
                $togetheridx[] = $i;
            } else {
                $apartidx[] = $i;
            }
        }

        // Per-rule no-value warnings (informative; empty values never constrain).
        foreach (array_keys($rules) as $i) {
            $missing = 0;
            foreach ($userids as $userid) {
                if ($val($i, $userid) === '') {
                    $missing++;
                }
            }
            if ($missing > 0) {
                $result->warnings[] = ['type' => self::WARNING_NOVALUE, 'rule' => $i, 'count' => $missing];
            }
        }

        // Cluster construction: composite tuple of every together rule's value.
        // Users empty on ALL together components fall to the singleton pool.
        $clusters = [];
        $singles = [];
        if ($togetheridx) {
            $map = [];
            foreach ($userids as $userid) {
                $parts = [];
                $allempty = true;
                foreach ($togetheridx as $i) {
                    $value = $val($i, $userid);
                    $parts[] = $value;
                    if ($value !== '') {
                        $allempty = false;
                    }
                }
                if ($allempty) {
                    $singles[] = $userid;
                } else {
                    $map[implode(self::KEY_SEPARATOR, $parts)][] = $userid;
                }
            }
            foreach ($map as $key => $members) {
                $clusters[] = ['key' => (string) $key, 'members' => $members];
            }
            usort($clusters, function (array $a, array $b): int {
                $bysize = count($b['members']) <=> count($a['members']);
                return $bysize !== 0 ? $bysize : strcmp($a['key'], $b['key']);
            });
        } else {
            /* Apart-only: everyone is placed one by one, ordered by the
               highest-priority apart rule's value groups (largest first, then
               value, members in candidate order, empty values last) — the
               same processing order the single-rule engine used. */
            $singles = self::order_by_first_apart($userids, $apartidx, $val);
        }

        // Contradiction scan: a pair inside one cluster sharing an apart value.
        // The composite binds every pair through the highest-priority together
        // rule; comparing that against the apart rule's position decides.
        $ejected = [];
        if ($clusters && $apartidx) {
            $mintogether = $togetheridx[0];
            $contradictions = [];
            foreach ($clusters as &$cluster) {
                foreach ($apartidx as $i) {
                    $byvalue = [];
                    foreach ($cluster['members'] as $userid) {
                        $value = $val($i, $userid);
                        if ($value !== '') {
                            $byvalue[$value][] = $userid;
                        }
                    }
                    foreach ($byvalue as $members) {
                        if (count($members) < 2) {
                            continue;
                        }
                        if ($i < $mintogether) {
                            // Apart outranks the together bond: keep the first
                            // member, eject the rest to be placed separately.
                            $eject = array_slice($members, 1);
                            $cluster['members'] = array_values(array_diff($cluster['members'], $eject));
                            $ejected = array_merge($ejected, $eject);
                            $winner = $i;
                        } else {
                            // The together bond prevails; the apart violations
                            // are inevitable and get counted at placement.
                            $winner = $mintogether;
                        }
                        $contradictions[$winner] = ($contradictions[$winner] ?? 0) + count($members) - 1;
                    }
                }
            }
            unset($cluster);
            ksort($contradictions);
            foreach ($contradictions as $rule => $count) {
                $result->warnings[] = ['type' => self::WARNING_CONTRADICTION, 'rule' => $rule, 'count' => $count];
            }
        }

        // Placement. Violations aggregate per (rule, value) for the warnings.
        $violations = [];
        foreach ($clusters as $cluster) {
            self::place_cluster($state, $cluster, $apartidx, $val, $violations, $result);
        }
        foreach (array_merge($ejected, $singles) as $userid) {
            self::place_single($state, $userid, $apartidx, $val, $violations, $result);
        }

        ksort($violations);
        foreach ($violations as $i => $byvalue) {
            ksort($byvalue);
            foreach ($byvalue as $value => $count) {
                $result->warnings[] = [
                    'type' => self::WARNING_APART,
                    'rule' => $i,
                    'value' => (string) $value,
                    'count' => $count,
                ];
            }
        }
    }

    /**
     * Place one cluster, splitting across groups when it does not fit.
     *
     * Groups are chosen by the lexicographic key (apart violations by rule
     * priority, most remaining capacity, smallest final size, group order) —
     * with no apart rules this is exactly the old keep-together packing.
     *
     * @param array $state Group state, modified in place.
     * @param array $cluster Cluster entry ('key' and 'members').
     * @param array $apartidx Apart rule indexes in priority order.
     * @param callable $val Value lookup: fn (ruleindex, userid) => trimmed string.
     * @param array $violations Aggregated (rule => value => count), modified in place.
     * @param allocation $result Receives unassigned users and warnings.
     * @return void
     */
    private static function place_cluster(
        array &$state,
        array $cluster,
        array $apartidx,
        callable $val,
        array &$violations,
        allocation $result
    ): void {
        $remainder = $cluster['members'];
        $parts = 0;
        while ($remainder) {
            $best = null;
            $bestkey = null;
            foreach ($state as $index => $groupstate) {
                if ($groupstate['remaining'] <= 0) {
                    continue;
                }
                $take = min(count($remainder), $groupstate['remaining']);
                $chunkvector = self::chunk_violations($groupstate, array_slice($remainder, 0, $take), $apartidx, $val);
                // Most remaining capacity first (fits big clusters), then the
                // smaller final size, then group order — the old tie chain.
                $key = array_merge($chunkvector, [-$groupstate['remaining'], $groupstate['final'], $index]);
                if ($best === null || $key < $bestkey) {
                    $best = $index;
                    $bestkey = $key;
                }
            }
            if ($best === null) {
                $result->unassigned = array_merge($result->unassigned, $remainder);
                break;
            }

            $take = min(count($remainder), $state[$best]['remaining']);
            $chunk = [];
            $rest = [];
            foreach ($remainder as $userid) {
                if (isset($state[$best]['existing'][$userid])) {
                    // Already a member of this group: nothing to do for them.
                    continue;
                }
                if (count($chunk) < $take) {
                    $chunk[] = $userid;
                } else {
                    $rest[] = $userid;
                }
            }
            self::assign_many($state[$best], $chunk, $apartidx, $val, $violations);
            $remainder = $rest;
            $parts++;
        }
        if ($parts > 1) {
            $result->warnings[] = [
                'type' => self::WARNING_SPLIT,
                'value' => str_replace(self::KEY_SEPARATOR, ' / ', $cluster['key']),
                'count' => $parts,
            ];
        }
    }

    /**
     * Place one unclustered user (apart-only mode, ejected users, no-value pool).
     *
     * Groups are chosen by (apart violations by rule priority, smallest final
     * size, group order) — with one apart rule this is exactly the old
     * keep-apart behaviour, and with empty values the old balanced fill.
     *
     * @param array $state Group state, modified in place.
     * @param int $userid The user to place.
     * @param array $apartidx Apart rule indexes in priority order.
     * @param callable $val Value lookup: fn (ruleindex, userid) => trimmed string.
     * @param array $violations Aggregated (rule => value => count), modified in place.
     * @param allocation $result Receives unassigned users.
     * @return void
     */
    private static function place_single(
        array &$state,
        int $userid,
        array $apartidx,
        callable $val,
        array &$violations,
        allocation $result
    ): void {
        $best = null;
        $bestkey = null;
        foreach ($state as $index => $groupstate) {
            if ($groupstate['remaining'] <= 0 || isset($groupstate['existing'][$userid])) {
                continue;
            }
            $vector = [];
            foreach ($apartidx as $i) {
                $value = $val($i, $userid);
                $held = ($value !== '') && isset($groupstate['values'][$i . self::KEY_SEPARATOR . $value]);
                $vector[] = $held ? 1 : 0;
            }
            $key = array_merge($vector, [$groupstate['final'], $index]);
            if ($best === null || $key < $bestkey) {
                $best = $index;
                $bestkey = $key;
            }
        }
        if ($best === null) {
            $result->unassigned[] = $userid;
            return;
        }
        self::assign_many($state[$best], [$userid], $apartidx, $val, $violations);
    }

    /**
     * Violations, per apart rule, of placing a chunk into a group as-is.
     *
     * Placing k members sharing a value costs k violations when the group
     * already holds the value, k - 1 otherwise.
     *
     * @param array $groupstate The group's state entry.
     * @param array $chunk Candidate member ids.
     * @param array $apartidx Apart rule indexes in priority order.
     * @param callable $val Value lookup: fn (ruleindex, userid) => trimmed string.
     * @return array Violation counts, one entry per apart rule in priority order.
     */
    private static function chunk_violations(array $groupstate, array $chunk, array $apartidx, callable $val): array {
        $vector = [];
        foreach ($apartidx as $i) {
            $counts = [];
            foreach ($chunk as $userid) {
                $value = $val($i, $userid);
                if ($value !== '') {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
            $total = 0;
            foreach ($counts as $value => $k) {
                $held = isset($groupstate['values'][$i . self::KEY_SEPARATOR . $value]);
                $total += $held ? $k : ($k - 1);
            }
            $vector[] = $total;
        }
        return $vector;
    }

    /**
     * Order apart-only users the way the single-rule engine processed them.
     *
     * By the first apart rule: value groups largest first, then value order,
     * members in candidate order; users without a value come last, in
     * candidate order.
     *
     * @param array $userids Ordered candidate ids.
     * @param array $apartidx Apart rule indexes in priority order.
     * @param callable $val Value lookup: fn (ruleindex, userid) => trimmed string.
     * @return array The reordered user ids.
     */
    private static function order_by_first_apart(array $userids, array $apartidx, callable $val): array {
        $first = $apartidx[0];
        $map = [];
        $novalue = [];
        foreach ($userids as $userid) {
            $value = $val($first, $userid);
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
        $ordered = [];
        foreach ($buckets as $bucket) {
            $ordered = array_merge($ordered, $bucket['members']);
        }
        return array_merge($ordered, $novalue);
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
            $state[$index]['assigned'][] = $userid;
            $state[$index]['final']++;
            $state[$index]['remaining']--;
        }
    }

    /**
     * Append users to a group's assignment, updating counters, held values and violations.
     *
     * @param array $groupstate One group's state entry, modified in place.
     * @param array $userids Users to add.
     * @param array $apartidx Apart rule indexes in priority order.
     * @param callable $val Value lookup: fn (ruleindex, userid) => trimmed string.
     * @param array $violations Aggregated (rule => value => count), modified in place.
     * @return void
     */
    private static function assign_many(
        array &$groupstate,
        array $userids,
        array $apartidx,
        callable $val,
        array &$violations
    ): void {
        foreach ($userids as $userid) {
            $groupstate['assigned'][] = $userid;
            $groupstate['final']++;
            $groupstate['remaining']--;
            foreach ($apartidx as $i) {
                $value = $val($i, $userid);
                if ($value === '') {
                    continue;
                }
                $key = $i . self::KEY_SEPARATOR . $value;
                if (isset($groupstate['values'][$key])) {
                    $violations[$i][$value] = ($violations[$i][$value] ?? 0) + 1;
                } else {
                    $groupstate['values'][$key] = true;
                }
            }
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
