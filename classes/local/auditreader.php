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
 * Paged, searchable reader over one audit run's stored snapshot.
 *
 * A run can hold thousands of participants spread over hundreds of groups, so
 * nothing here ever materialises the whole run as member rows: the group
 * sections come from the run's own snapshot (one decoded text column), member
 * rows are fetched one window at a time, and the "why here?" explanations are
 * derived for the window only.
 *
 * The explanation pass is the one place that still reads beyond the window,
 * and only as far as it must: keep-together facts are group-local, so only the
 * window's own groups are scanned, while keep-apart facts name peers anywhere
 * in the run and therefore scan it once per window. Both keep at most
 * PEER_CAP + 1 peer rows per (rule, value, group) bucket, which is what stops
 * the quadratic blow-up the unpaged reader had — a cohort rule stores the same
 * value for every participant, so one bucket used to hold the entire run and
 * every member walked all of it.
 *
 * Everything displayed comes from the stored snapshot — rule labels and group
 * names as of apply time, per-participant values and outcomes — never from the
 * live tables and never by replaying the engine.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auditreader {
    /** @var int Group sections listed per page. */
    public const SECTIONS_PER_PAGE = 8;

    /** @var int Members listed per section window: "show more" and the pinned view. */
    public const MEMBERS_PER_PAGE = 20;

    /**
     * Members a section card opens with, before "show more".
     *
     * Smaller than a window on purpose. Sections lay out three to a row, so a
     * card is a third of the width and its job is to be scannable next to its
     * neighbours; the reader who wants the whole group opens it on its own
     * page. The first "show more" still pulls a full MEMBERS_PER_PAGE, so a
     * fifty-member group is three clicks (5 + 20 + 20 + 5) rather than the nine
     * it would take if the card's window were also the increment.
     *
     * @var int
     */
    public const MEMBERS_PREVIEW = 5;

    /** @var int Most peers named in one keep-apart explanation line. */
    public const PEER_CAP = 5;

    /** @var int Rows read per batch by the peer pass. */
    public const SCAN_CHUNK = 2000;

    /** @var \stdClass The run record. */
    protected \stdClass $run;

    /** @var \core\context\course The course context (viewer-side checks). */
    protected \core\context\course $context;

    /** @var array Decoded ruleset entries, in priority order. */
    protected array $rules;

    /** @var array Per-rule display info: index, label, apart, cohort, masked. */
    protected array $ruleinfo;

    /** @var array Snapshot group entries, in snapshot order. */
    protected array $snapshotgroups;

    /** @var array Snapshot group names by id, plus 0 => the no-group label. */
    protected array $groupnames;

    /** @var array Names the group search matches against, by group id. */
    protected array $searchnames;

    /** @var array|null Live groups of the course, loaded on first use. */
    protected ?array $livegroups = null;

    /** @var array|null Country name list, loaded on first use. */
    protected ?array $countries = null;

    /**
     * Constructor.
     *
     * @param \stdClass $run The run record.
     * @param \core\context\course $context The course context.
     */
    public function __construct(\stdClass $run, \core\context\course $context) {
        $this->run = $run;
        $this->context = $context;
        $this->rules = array_values(json_decode($run->rulesjson, true)['rules'] ?? []);
        $this->snapshotgroups = json_decode($run->groupsjson, true) ?? [];

        /* Reader-side masking: a rule whose source the VIEWER may not see
           keeps its label but never shows values or value-derived facts. */
        $this->ruleinfo = [];
        foreach ($this->rules as $i => $rule) {
            $this->ruleinfo[$i] = [
                'index' => $i + 1,
                'label' => (string) ($rule['label'] ?? $rule['source']),
                'apart' => ($rule['mode'] ?? '') === 'apart',
                'cohort' => (bool) ruleset::source_cohortid((string) ($rule['source'] ?? '')),
                'masked' => !profilefields::is_allowed((string) ($rule['source'] ?? ''), $context),
            ];
        }

        $this->groupnames = [0 => get_string('auditnogroup', 'local_groupdist')];
        $this->searchnames = [0 => [$this->groupnames[0]]];
        foreach ($this->snapshotgroups as $group) {
            $groupid = (int) $group['id'];
            $raw = (string) $group['name'];
            /* escape => false because the name lands in a Mustache double
               stash and in a PARAM_TEXT web service field: format_string()'s
               own encoding would be escaped a second time on the way to the
               screen, so a group called "R&D" would read "R&amp;D". */
            $this->groupnames[$groupid] = format_string($raw, true, [
                'context' => $context,
                'escape' => false,
            ]);
            // Filters may rewrite the name, so the search accepts either form.
            $this->searchnames[$groupid] = [$raw, $this->groupnames[$groupid]];
        }
    }

    /**
     * Per-rule display info, keyed by zero-based rule position.
     *
     * @return array The rule info list.
     */
    public function get_rule_info(): array {
        return $this->ruleinfo;
    }

    /**
     * The run record this reader was built for.
     *
     * @return \stdClass The run record.
     */
    public function get_run(): \stdClass {
        return $this->run;
    }

    /**
     * One page of group sections, each carrying its first window of members.
     *
     * Sections come from the snapshot, so a group emptied or deleted since the
     * run still appears. A participant search drops the sections it leaves
     * empty; without one, every snapshot group is listed even when empty,
     * exactly as the unpaged reader did.
     *
     * @param string $groupquery Group name search ('' matches every section).
     * @param string $userquery Participant name search ('' matches everyone).
     * @param int $page Zero-based page of sections.
     * @param int $perpage Sections per page.
     * @return array Keys 'total', 'matchingmembers', 'sections'.
     */
    public function get_sections(string $groupquery, string $userquery, int $page, int $perpage): array {
        $userquery = trim($userquery);
        $counts = $this->count_by_group($userquery);

        $candidates = [];
        foreach ($this->snapshotgroups as $group) {
            $candidates[] = ['id' => (int) $group['id'], 'seats' => $group['seats'] ?? null];
        }
        // The no-group bucket is listed last and only when it holds someone.
        if (($counts[0] ?? 0) > 0) {
            $candidates[] = ['id' => 0, 'seats' => null];
        }

        $sections = [];
        $matching = 0;
        foreach ($candidates as $candidate) {
            $groupid = $candidate['id'];
            if ($userquery !== '' && empty($counts[$groupid])) {
                continue;
            }
            if (!self::name_matches($this->searchnames[$groupid] ?? [], $groupquery)) {
                continue;
            }
            // Counted over the surviving sections only: with a group search
            // active, the run's own total would describe participants the
            // reader is not being shown.
            $matching += (int) ($counts[$groupid] ?? 0);
            $sections[] = $candidate;
        }

        $total = count($sections);
        $window = array_slice($sections, max(0, $page) * $perpage, $perpage);

        // One explanation pass covers every member of the whole page.
        $rowsbysection = [];
        $allrows = [];
        foreach ($window as $section) {
            $rows = $this->fetch_rows($section['id'], $userquery, 0, self::MEMBERS_PREVIEW);
            $rowsbysection[$section['id']] = $rows;
            $allrows = array_merge($allrows, $rows);
        }
        $members = $this->build_members($allrows);

        $payload = [];
        foreach ($window as $section) {
            $groupid = $section['id'];
            $rows = $rowsbysection[$groupid];
            $membertotal = (int) ($counts[$groupid] ?? 0);
            $shown = [];
            foreach ($rows as $row) {
                $shown[] = $members[(int) $row->id];
            }
            $payload[] = [
                'id' => $groupid,
                'name' => $this->groupnames[$groupid] ?? '',
                'nogroup' => ($groupid === 0),
                'deleted' => ($groupid !== 0 && !isset($this->live_groups()[$groupid])),
                'seats' => $section['seats'],
                'membertotal' => $membertotal,
                'shown' => count($shown),
                'hasmore' => (count($shown) < $membertotal),
                'members' => $shown,
            ];
        }

        return [
            'total' => $total,
            'matchingmembers' => $matching,
            'sections' => $payload,
        ];
    }

    /**
     * Whether a group id addresses a section of this run's snapshot.
     *
     * @param int $groupid The group id (0 = the no-group bucket).
     * @return bool Whether the section exists.
     */
    public function has_section(int $groupid): bool {
        return isset($this->groupnames[$groupid]);
    }

    /**
     * The section header of one group, without any member.
     *
     * @param int $groupid The snapshot group id (0 = the no-group bucket).
     * @return array The section context, members left empty.
     */
    public function get_section_header(int $groupid): array {
        $seats = null;
        foreach ($this->snapshotgroups as $group) {
            if ((int) $group['id'] === $groupid) {
                $seats = $group['seats'] ?? null;
                break;
            }
        }
        return [
            'id' => $groupid,
            'name' => $this->groupnames[$groupid] ?? '',
            'nogroup' => ($groupid === 0),
            'deleted' => ($groupid !== 0 && !isset($this->live_groups()[$groupid])),
            'seats' => $seats,
            'membertotal' => 0,
            'shown' => 0,
            'hasmore' => false,
            'members' => [],
        ];
    }

    /**
     * One window of members inside a single section.
     *
     * @param int $groupid The snapshot group id (0 = the no-group bucket).
     * @param string $userquery Participant name search ('' matches everyone).
     * @param int $limitfrom Window offset.
     * @param int $limitnum Window size.
     * @return array Keys 'total' and 'members'.
     */
    public function get_members(int $groupid, string $userquery, int $limitfrom, int $limitnum): array {
        $userquery = trim($userquery);
        $rows = $this->fetch_rows($groupid, $userquery, $limitfrom, $limitnum);
        $members = $this->build_members($rows);

        $ordered = [];
        foreach ($rows as $row) {
            $ordered[] = $members[(int) $row->id];
        }
        return [
            'total' => (int) ($this->count_by_group($userquery)[$groupid] ?? 0),
            'members' => $ordered,
        ];
    }

    /**
     * Localise the snapshot warnings using the snapshot rule labels.
     *
     * @return array List of ['message' => ...].
     */
    public function get_warnings(): array {
        $formatted = [];
        foreach ((json_decode($this->run->warningsjson, true) ?? []) as $warning) {
            $type = $warning['type'] ?? '';
            $count = (int) ($warning['count'] ?? 0);
            $ruleindex = $warning['rule'] ?? null;
            $known = ($ruleindex !== null && isset($this->ruleinfo[$ruleindex]));
            $label = $known ? $this->ruleinfo[$ruleindex]['label'] : '';
            $masked = $known ? $this->ruleinfo[$ruleindex]['masked'] : false;
            $rawvalue = (string) ($warning['value'] ?? '');
            $value = $masked
                ? get_string('auditmaskedvalue', 'local_groupdist')
                : ($known ? $this->display_value($ruleindex, $rawvalue) : $rawvalue);

            switch ($type) {
                case 'unassigned':
                    $message = get_string('warningunassigned', 'local_groupdist', $count);
                    break;
                case 'affinitysplit':
                    /* The split value is the composite tuple of every
                       keep-together rule and names no single rule, so it is
                       masked as a whole as soon as any of them is masked —
                       otherwise a value the reader may not see leaks here
                       while the same value stays hidden in the members list. */
                    $message = get_string('warningsplit', 'local_groupdist', (object) [
                        'value' => $this->has_masked_together_rule()
                            ? get_string('auditmaskedvalue', 'local_groupdist')
                            : $rawvalue,
                        'count' => $count,
                    ]);
                    break;
                case 'apartinfeasible':
                    $message = get_string('warningapart', 'local_groupdist', (object) [
                        'value' => $value,
                        'field' => $label,
                        'count' => $count,
                    ]);
                    break;
                case 'novalue':
                    $message = get_string('warningnovalue', 'local_groupdist', (object) [
                        'count' => $count,
                        'field' => $label,
                    ]);
                    break;
                case 'affinitycontradiction':
                    $message = get_string('warningcontradiction', 'local_groupdist', (object) [
                        'count' => $count,
                        'field' => $label,
                    ]);
                    break;
                default:
                    // Builder warnings (no seats, communication) and future types.
                    $message = get_string('warningnoseats', 'local_groupdist', (object) [
                        'count' => $count,
                        'field' => fields::get_seats_label(),
                    ]);
                    if ($type !== 'noseats') {
                        $message = ($type === 'commslow')
                            ? get_string('warningcommslow', 'local_groupdist')
                            : $type;
                    }
            }
            $formatted[] = ['message' => $message];
        }
        return $formatted;
    }

    /**
     * Whether any keep-together rule of this run is masked for the viewer.
     *
     * @return bool Whether a together rule is hidden from the reader.
     */
    protected function has_masked_together_rule(): bool {
        foreach ($this->ruleinfo as $info) {
            if (!$info['apart'] && $info['masked']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Localised outcome badge data.
     *
     * @param int $writestatus A runlog WRITE_* value.
     * @return array Keys 'label' and 'class' (Bootstrap suffix).
     */
    public static function outcome_badge(int $writestatus): array {
        switch ($writestatus) {
            case runlog::WRITE_WRITTEN:
                /* Not notable: on a run that worked, every row says this, and a
                   column of identical badges tells the reader nothing. The
                   label stays in the payload for anyone who needs it — the
                   templates simply do not paint it. */
                return [
                    'label' => get_string('auditoutcomewritten', 'local_groupdist'),
                    'class' => 'success',
                    'notable' => false,
                ];
            case runlog::WRITE_FAILED:
                return [
                    'label' => get_string('auditoutcomefailed', 'local_groupdist'),
                    'class' => 'danger',
                    'notable' => true,
                ];
            case runlog::WRITE_UNASSIGNED:
                return [
                    'label' => get_string('auditoutcomeunassigned', 'local_groupdist'),
                    'class' => 'warning',
                    'notable' => true,
                ];
            case runlog::WRITE_SKIPPED:
                return [
                    'label' => get_string('auditoutcomeskipped', 'local_groupdist'),
                    'class' => 'secondary',
                    'notable' => true,
                ];
            default:
                return [
                    'label' => get_string('auditoutcomeplanned', 'local_groupdist'),
                    'class' => 'secondary',
                    'notable' => true,
                ];
        }
    }

    /**
     * Case-insensitive substring match used by the group name search.
     *
     * @param array $names The candidate names of one group.
     * @param string $query The search text ('' matches everything).
     * @return bool Whether any candidate matches.
     */
    protected static function name_matches(array $names, string $query): bool {
        $query = trim($query);
        if ($query === '') {
            return true;
        }
        $needle = \core_text::strtolower($query);
        foreach ($names as $name) {
            if (\core_text::strpos(\core_text::strtolower((string) $name), $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Member counts per group id, honouring the participant name search.
     *
     * @param string $userquery Participant name search ('' matches everyone).
     * @return array Map of groupid => count.
     */
    protected function count_by_group(string $userquery): array {
        global $DB;

        [$where, $params] = $this->search_where($userquery);
        $records = $DB->get_records_sql(
            "SELECT ru.groupid, COUNT(1) AS memberscount
               FROM {local_groupdist_run_user} ru
          LEFT JOIN {user} u ON u.id = ru.userid
              WHERE ru.runid = :runid {$where}
           GROUP BY ru.groupid",
            $params + ['runid' => (int) $this->run->id]
        );
        $counts = [];
        foreach ($records as $record) {
            $counts[(int) $record->groupid] = (int) $record->memberscount;
        }
        return $counts;
    }

    /**
     * The participant name predicate, or an empty one.
     *
     * A LEFT JOIN plus this predicate behaves as an inner join: pseudonymised
     * rows have no user row, so they never match a name search.
     *
     * @param string $userquery Participant name search ('' matches everyone).
     * @return array [$where, $params] with $where already prefixed by AND.
     */
    protected function search_where(string $userquery): array {
        global $DB;

        $userquery = trim($userquery);
        if ($userquery === '') {
            return ['', []];
        }
        // The stored fullname format decides which columns are searched, so a
        // site displaying "lastname firstname" matches what the reader reads.
        [$fullname, $params] = \core_user\fields::get_sql_fullname('u');
        $params['gdusersearch'] = '%' . $DB->sql_like_escape($userquery) . '%';
        return [' AND ' . $DB->sql_like($fullname, ':gdusersearch', false, false), $params];
    }

    /**
     * One window of run_user rows of a section, ordered by display name.
     *
     * @param int $groupid The snapshot group id.
     * @param string $userquery Participant name search ('' matches everyone).
     * @param int $limitfrom Window offset.
     * @param int $limitnum Window size.
     * @return array The rows, in display order.
     */
    protected function fetch_rows(int $groupid, string $userquery, int $limitfrom, int $limitnum): array {
        global $DB;

        [$where, $params] = $this->search_where($userquery);
        [$order, $orderparams] = \core_user\fields::get_sql_fullname('u');
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        return array_values($DB->get_records_sql(
            "SELECT ru.id, ru.userid, ru.valuesjson, ru.groupid, ru.writestatus, u.deleted, {$namefields}
               FROM {local_groupdist_run_user} ru
          LEFT JOIN {user} u ON u.id = ru.userid
              WHERE ru.runid = :runid AND ru.groupid = :groupid {$where}
           ORDER BY {$order}, ru.id",
            $params + $orderparams + ['runid' => (int) $this->run->id, 'groupid' => $groupid],
            max(0, $limitfrom),
            max(0, $limitnum)
        ));
    }

    /**
     * Build the member contexts for one window of rows.
     *
     * @param array $rows The run_user rows of the window.
     * @return array Map of run_user id => member context.
     */
    protected function build_members(array $rows): array {
        if (!$rows) {
            return [];
        }
        $peers = $this->collect_peers($rows);

        // Pass one plans the lines and collects the peer ids actually named;
        // pass two turns them into text once every name is resolved.
        $plans = [];
        $peerids = [];
        foreach ($rows as $row) {
            $plan = $this->plan_explanations($row, $peers);
            foreach ($plan as $entry) {
                foreach ($entry['peers'] ?? [] as $peer) {
                    if ($peer['userid'] > 0) {
                        $peerids[$peer['userid']] = true;
                    }
                }
            }
            $plans[(int) $row->id] = $plan;
        }
        $peernames = $this->resolve_names(array_keys($peerids));

        $members = [];
        foreach ($rows as $row) {
            $members[(int) $row->id] = $this->export_member($row, $plans[(int) $row->id], $peernames);
        }
        return $members;
    }

    /**
     * Peer facts for every (rule, value) the window's rows hold.
     *
     * @param array $rows The run_user rows of the window.
     * @return array $peers[ruleindex][value][groupid] = ['count' => int, 'rows' => [rowid => userid]].
     */
    protected function collect_peers(array $rows): array {
        global $DB;

        $needed = [];
        $hasapart = false;
        $groupids = [];
        foreach ($rows as $row) {
            $groupids[(int) $row->groupid] = true;
            foreach ($this->row_values($row) as $i => $value) {
                if ($value === '' || empty($this->ruleinfo[$i]) || $this->ruleinfo[$i]['masked']) {
                    continue;
                }
                $needed[$i][$value] = true;
                $hasapart = $hasapart || $this->ruleinfo[$i]['apart'];
            }
        }
        if (!$needed) {
            return [];
        }

        /* Keep-together facts are group-local, so only the window's own groups
           are read; keep-apart lines name peers anywhere, which costs one pass
           over the run's rows. */
        $params = ['runid' => (int) $this->run->id];
        $where = '';
        if (!$hasapart) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($groupids), SQL_PARAMS_NAMED, 'gdgrp');
            $where = " AND ru.groupid {$insql}";
            $params += $inparams;
        }

        /* Read in keyset chunks rather than through one recordset: neither
           driver streams a plain recordset — mysqli buffers the whole result
           with MYSQLI_STORE_RESULT and pgsql fetches 100000 rows per cursor
           batch — so a large run would be materialised in full to produce a
           page. Only the accumulators below survive a chunk. */
        $peers = [];
        $lastid = 0;
        do {
            $chunk = $DB->get_records_sql(
                "SELECT ru.id, ru.userid, ru.groupid, ru.valuesjson
                   FROM {local_groupdist_run_user} ru
                  WHERE ru.runid = :runid AND ru.id > :lastid {$where}
               ORDER BY ru.id",
                $params + ['lastid' => $lastid],
                0,
                self::SCAN_CHUNK
            );
            foreach ($chunk as $record) {
                $lastid = (int) $record->id;
                $groupid = (int) $record->groupid;
                foreach ($this->row_values($record) as $i => $value) {
                    if ($value === '' || !isset($needed[$i][$value])) {
                        continue;
                    }
                    if (!isset($peers[$i][$value][$groupid])) {
                        $peers[$i][$value][$groupid] = ['count' => 0, 'rows' => []];
                    }
                    $peers[$i][$value][$groupid]['count']++;
                    // One row over the display cap: a member's own row is
                    // dropped from its own bucket before the list is sliced.
                    if (count($peers[$i][$value][$groupid]['rows']) <= self::PEER_CAP) {
                        $peers[$i][$value][$groupid]['rows'][(int) $record->id] = (int) $record->userid;
                    }
                }
            }
        } while (count($chunk) === self::SCAN_CHUNK);

        return $peers;
    }

    /**
     * Plan one member's explanation lines from the collected peer facts.
     *
     * @param \stdClass $row The member's run_user row.
     * @param array $peers The collected peer facts.
     * @return array Line plans: type, rule index, value, counts and peer refs.
     */
    protected function plan_explanations(\stdClass $row, array $peers): array {
        $values = $this->row_values($row);
        $groupid = (int) $row->groupid;
        $plan = [];

        foreach ($this->ruleinfo as $i => $info) {
            /* The masked note is emitted whether or not this participant holds
               a value: firing it only on a non-empty value would tell a reader
               who may not see the field which participants have one. */
            if ($info['masked']) {
                $plan[] = ['type' => 'masked', 'rule' => $i];
                continue;
            }
            $value = $values[$i] ?? '';
            if ($value === '') {
                continue;
            }
            $buckets = $peers[$i][$value] ?? [];
            if (!$info['apart']) {
                $count = (int) ($buckets[$groupid]['count'] ?? 0);
                $plan[] = [
                    'type' => 'together',
                    'rule' => $i,
                    'value' => $value,
                    'count' => max(0, $count - 1),
                ];
                continue;
            }

            $separated = [];
            $conflicting = [];
            $separatedcount = 0;
            $conflictingcount = 0;
            foreach ($buckets as $bucketgroupid => $bucket) {
                $bucketrows = $bucket['rows'];
                $count = (int) $bucket['count'];
                if ($bucketgroupid === $groupid) {
                    // The member's own row sits in this bucket; it is not a peer.
                    $count = max(0, $count - 1);
                    unset($bucketrows[(int) $row->id]);
                }
                // Sharing the no-group bucket is not a keep-apart violation.
                if ($groupid !== 0 && $bucketgroupid === $groupid) {
                    $conflictingcount += $count;
                    foreach ($bucketrows as $peerid => $peeruserid) {
                        $conflicting[$peerid] = ['userid' => $peeruserid, 'groupid' => $bucketgroupid];
                    }
                    continue;
                }
                $separatedcount += $count;
                foreach ($bucketrows as $peerid => $peeruserid) {
                    $separated[$peerid] = ['userid' => $peeruserid, 'groupid' => $bucketgroupid];
                }
            }
            // Row id order across buckets reproduces the snapshot order.
            ksort($separated);
            ksort($conflicting);

            if ($separatedcount > 0) {
                $plan[] = [
                    'type' => 'apart',
                    'rule' => $i,
                    'count' => $separatedcount,
                    'peers' => array_slice($separated, 0, self::PEER_CAP),
                    'withgroup' => true,
                ];
            }
            if ($conflictingcount > 0) {
                $plan[] = [
                    'type' => 'apartsame',
                    'rule' => $i,
                    'count' => $conflictingcount,
                    'peers' => array_slice($conflicting, 0, self::PEER_CAP),
                    'withgroup' => false,
                ];
            }
        }
        return $plan;
    }

    /**
     * Turn one member's line plan into the rendered member context.
     *
     * @param \stdClass $row The member's run_user row.
     * @param array $plan The planned lines.
     * @param array $peernames Resolved peer names by user id.
     * @return array The member context.
     */
    protected function export_member(\stdClass $row, array $plan, array $peernames): array {
        $why = [];
        foreach ($plan as $entry) {
            $info = $this->ruleinfo[$entry['rule']];
            switch ($entry['type']) {
                case 'masked':
                    $why[] = ['text' => get_string('auditwhymasked', 'local_groupdist', (object) [
                        'index' => $info['index'],
                        'label' => $info['label'],
                    ]), 'muted' => true, 'warn' => false];
                    break;
                case 'together':
                    $why[] = ['text' => get_string('auditwhytogether', 'local_groupdist', (object) [
                        'index' => $info['index'],
                        'label' => $info['label'],
                        'value' => $this->display_value($entry['rule'], $entry['value']),
                        'count' => $entry['count'],
                    ]), 'muted' => false, 'warn' => false];
                    break;
                default:
                    $names = [];
                    foreach ($entry['peers'] as $peer) {
                        $name = ($peer['userid'] > 0 && isset($peernames[$peer['userid']]))
                            ? $peernames[$peer['userid']]
                            : get_string('auditremoved', 'local_groupdist');
                        $names[] = $entry['withgroup']
                            ? $name . ' (' . ($this->groupnames[$peer['groupid']] ?? '') . ')'
                            : $name;
                    }
                    $others = implode(', ', $names) . (($entry['count'] > count($names)) ? ', …' : '');
                    $key = ($entry['type'] === 'apart') ? 'auditwhyapart' : 'auditwhyapartsame';
                    $why[] = ['text' => get_string($key, 'local_groupdist', (object) [
                        'index' => $info['index'],
                        'label' => $info['label'],
                        'others' => $others,
                    ]), 'muted' => false, 'warn' => ($entry['type'] === 'apartsame')];
            }
        }
        if (!$why) {
            $why[] = ['text' => get_string('auditwhynone', 'local_groupdist'), 'muted' => true, 'warn' => false];
        }

        // A pseudonymised row carries userid 0; a user id whose account is gone
        // leaves the LEFT JOIN empty. Both render as a removed participant.
        $userid = (int) $row->userid;
        $removed = ($userid === 0 || $row->deleted === null);
        $profileurl = '';
        if (!$removed && empty($row->deleted)) {
            // The destination enforces profile visibility, as core's own
            // report links do; a deleted user has no profile page to reach.
            $profileurl = \core_user::get_profile_url((object) ['id' => $userid], $this->context)->out(false);
        }

        return [
            'name' => $removed ? get_string('auditremoved', 'local_groupdist') : fullname($row),
            'removed' => $removed,
            'profileurl' => $profileurl,
            'groupname' => $this->groupnames[(int) $row->groupid] ?? '',
            'outcome' => self::outcome_badge((int) $row->writestatus),
            'why' => $why,
        ];
    }

    /**
     * Resolve display names for a batch of user ids.
     *
     * @param array $userids The user ids.
     * @return array Map of user id => display name.
     */
    protected function resolve_names(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }
        $namefields = implode(',', \core_user\fields::for_name()->get_required_fields());
        $names = [];
        foreach (array_chunk($userids, 1000) as $chunk) {
            foreach ($DB->get_records_list('user', 'id', $chunk, '', 'id,' . $namefields) as $user) {
                $names[(int) $user->id] = fullname($user);
            }
        }
        return $names;
    }

    /**
     * The trimmed per-rule values of one row.
     *
     * @param \stdClass $row A run_user row carrying valuesjson.
     * @return array Values by zero-based rule position.
     */
    protected function row_values(\stdClass $row): array {
        $values = json_decode((string) $row->valuesjson, true);
        if (!is_array($values)) {
            return [];
        }
        return array_map(function ($value): string {
            return trim((string) $value);
        }, $values);
    }

    /**
     * The display form of one rule value.
     *
     * @param int $ruleindex Zero-based rule position.
     * @param string $value The stored value.
     * @return string The display value.
     */
    protected function display_value(int $ruleindex, string $value): string {
        if (!empty($this->ruleinfo[$ruleindex]['cohort'])) {
            return $this->ruleinfo[$ruleindex]['label'];
        }
        if (($this->rules[$ruleindex]['source'] ?? '') === 'country') {
            if ($this->countries === null) {
                $this->countries = get_string_manager()->get_list_of_countries(true);
            }
            return $this->countries[$value] ?? $value;
        }
        /* A profile field value is arbitrary stored text — a textarea source
           can hold markup. format_string() strips it, which is also what keeps
           the value passable through the web service's PARAM_TEXT fields;
           escape => false because the caller renders it escaped already. */
        return format_string($value, true, ['context' => $this->context, 'escape' => false]);
    }

    /**
     * The course's live groups, for the deleted-group marker.
     *
     * @return array Groups by id.
     */
    protected function live_groups(): array {
        if ($this->livegroups === null) {
            $this->livegroups = groups_get_all_groups((int) $this->run->courseid);
        }
        return $this->livegroups;
    }
}
