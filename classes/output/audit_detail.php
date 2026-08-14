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

namespace local_groupdist\output;

use local_groupdist\local\profilefields;
use local_groupdist\local\ruleset;
use local_groupdist\local\runlog;

/**
 * Audit run detail: the snapshot rendered, with per-participant explanations.
 *
 * Everything shown comes from the stored snapshot — rule labels and group
 * names as of apply time, per-participant values and outcomes — never from
 * the live tables, and never by replaying the engine. Two reader-side
 * overlays apply at render time only: rule values the VIEWER may not see are
 * masked (the snapshot stays intact), and pseudonymised rows appear as
 * removed participants.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_detail implements \renderable, \templatable {
    /** @var int Most peers named in one keep-apart explanation line. */
    public const OTHERS_CAP = 5;

    /** @var \stdClass The run record. */
    protected \stdClass $run;

    /** @var \core\context\course The course context (viewer-side checks). */
    protected \core\context\course $context;

    /**
     * Constructor.
     *
     * @param \stdClass $run The run record.
     * @param \core\context\course $context The course context.
     */
    public function __construct(\stdClass $run, \core\context\course $context) {
        $this->run = $run;
        $this->context = $context;
    }

    /**
     * Export the template context.
     *
     * @param \renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(\renderer_base $output): array {
        global $DB;

        $run = $this->run;
        $rules = array_values(json_decode($run->rulesjson, true)['rules'] ?? []);
        $snapshotgroups = json_decode($run->groupsjson, true) ?? [];
        $rows = array_values($DB->get_records('local_groupdist_run_user', ['runid' => $run->id], 'id'));

        /* Reader-side masking: a rule whose source the VIEWER may not see
           keeps its label but never shows values or value-derived facts. */
        $ruleinfo = [];
        foreach ($rules as $i => $rule) {
            $ruleinfo[$i] = [
                'index' => $i + 1,
                'label' => (string) ($rule['label'] ?? $rule['source']),
                'apart' => ($rule['mode'] ?? '') === 'apart',
                'cohort' => (bool) ruleset::source_cohortid((string) ($rule['source'] ?? '')),
                'masked' => !profilefields::is_allowed((string) ($rule['source'] ?? ''), $this->context),
            ];
        }
        $countries = null;
        $display = function (int $i, string $value) use ($rules, $ruleinfo, &$countries): string {
            if ($ruleinfo[$i]['cohort']) {
                return $ruleinfo[$i]['label'];
            }
            if (($rules[$i]['source'] ?? '') === 'country') {
                if ($countries === null) {
                    $countries = get_string_manager()->get_list_of_countries(true);
                }
                return $countries[$value] ?? $value;
            }
            return $value;
        };

        // Resolve current names for the surviving userids; group names come
        // from the snapshot, with a deleted marker when the id is gone now.
        $userids = array_filter(array_map(function (\stdClass $row): int {
            return (int) $row->userid;
        }, $rows));
        $names = [];
        if ($userids) {
            $namefields = implode(',', \core_user\fields::for_name()->get_required_fields());
            $names = $DB->get_records_list('user', 'id', $userids, '', 'id,' . $namefields);
        }
        $livegroups = groups_get_all_groups((int) $run->courseid);
        $groupnames = [0 => get_string('auditnogroup', 'local_groupdist')];
        foreach ($snapshotgroups as $group) {
            $groupnames[(int) $group['id']] = format_string((string) $group['name'], true, ['context' => $this->context]);
        }
        $membername = function (\stdClass $row) use ($names): string {
            $userid = (int) $row->userid;
            if ($userid > 0 && isset($names[$userid])) {
                return fullname($names[$userid]);
            }
            return get_string('auditremoved', 'local_groupdist');
        };

        // Per-rule value buckets over the whole run, for the explanations.
        $values = [];
        $buckets = [];
        foreach ($rows as $rowindex => $row) {
            $rowvalues = json_decode($row->valuesjson, true) ?: [];
            $values[$rowindex] = $rowvalues;
            foreach ($rowvalues as $i => $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $buckets[$i][$value][] = $rowindex;
                }
            }
        }

        $bygroup = [];
        foreach ($rows as $rowindex => $row) {
            $bygroup[(int) $row->groupid][] = $rowindex;
        }

        $groupspayload = [];
        $sections = $snapshotgroups;
        $sections[] = ['id' => 0, 'name' => '', 'seats' => null, 'current' => 0];
        foreach ($sections as $group) {
            $groupid = (int) $group['id'];
            $members = [];
            foreach ($bygroup[$groupid] ?? [] as $rowindex) {
                $members[] = $this->export_member(
                    $rowindex,
                    $rows,
                    $values,
                    $buckets,
                    $ruleinfo,
                    $display,
                    $membername,
                    $groupnames
                );
            }
            if ($groupid === 0 && !$members) {
                continue;
            }
            $groupspayload[] = [
                'name' => $groupnames[$groupid],
                'nogroup' => ($groupid === 0),
                'deleted' => ($groupid !== 0 && !isset($livegroups[$groupid])),
                'seats' => $group['seats'] ?? null,
                'members' => $members,
                'membercount' => count($members),
            ];
        }

        $ruleschips = [];
        foreach ($ruleinfo as $info) {
            $ruleschips[] = [
                'index' => $info['index'],
                'label' => $info['label'],
                'apart' => $info['apart'],
                'mode' => $info['apart']
                    ? get_string('modeapart', 'local_groupdist')
                    : get_string('modetogether', 'local_groupdist'),
                'masked' => $info['masked'],
            ];
        }

        return [
            'heading' => get_string('auditrun', 'local_groupdist', userdate(
                (int) $run->timecreated,
                get_string('strftimedatetimeshort', 'langconfig')
            )),
            'byname' => $this->applier_name(),
            'meta' => get_string('auditmeta', 'local_groupdist', (object) [
                'seed' => (int) $run->seed,
                'version' => (string) $run->pluginversion,
                'total' => (int) $run->memberstotal,
                'written' => (int) $run->memberswritten,
            ]),
            'status' => audit_list::status_badge((int) $run->status),
            'restored' => (bool) $run->restored,
            'rules' => $ruleschips,
            'hasrules' => (bool) $ruleschips,
            'warnings' => $this->export_warnings($ruleinfo, $display),
            'groups' => $groupspayload,
            'backurl' => (new \moodle_url('/local/groupdist/audit.php', ['id' => (int) $run->courseid]))->out(false),
        ];
    }

    /**
     * Export one member row with its explanations.
     *
     * @param int $rowindex Index of the member in the rows list.
     * @param array $rows All run_user rows.
     * @param array $values Decoded per-row value lists.
     * @param array $buckets Per-rule value buckets (rule => value => row indexes).
     * @param array $ruleinfo Per-rule display info.
     * @param callable $display Value display mapper fn (ruleindex, value).
     * @param callable $membername Name resolver fn (row).
     * @param array $groupnames Snapshot group names by id.
     * @return array The member context.
     */
    protected function export_member(
        int $rowindex,
        array $rows,
        array $values,
        array $buckets,
        array $ruleinfo,
        callable $display,
        callable $membername,
        array $groupnames
    ): array {
        $row = $rows[$rowindex];
        $why = [];
        foreach ($ruleinfo as $i => $info) {
            $value = trim((string) ($values[$rowindex][$i] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($info['masked']) {
                $why[] = ['text' => get_string('auditwhymasked', 'local_groupdist', (object) [
                    'index' => $info['index'],
                    'label' => $info['label'],
                ]), 'muted' => true, 'warn' => false];
                continue;
            }
            $peers = $buckets[$i][$value] ?? [];
            if (!$info['apart']) {
                $samegroup = 0;
                foreach ($peers as $peerindex) {
                    if ($peerindex !== $rowindex && (int) $rows[$peerindex]->groupid === (int) $row->groupid) {
                        $samegroup++;
                    }
                }
                $why[] = ['text' => get_string('auditwhytogether', 'local_groupdist', (object) [
                    'index' => $info['index'],
                    'label' => $info['label'],
                    'value' => $display($i, $value),
                    'count' => $samegroup,
                ]), 'muted' => false, 'warn' => false];
                continue;
            }
            $separated = [];
            $conflicting = [];
            foreach ($peers as $peerindex) {
                if ($peerindex === $rowindex) {
                    continue;
                }
                $peer = $rows[$peerindex];
                $peername = $membername($peer) . ' (' . ($groupnames[(int) $peer->groupid] ?? '') . ')';
                if ((int) $peer->groupid !== 0 && (int) $peer->groupid === (int) $row->groupid) {
                    $conflicting[] = $membername($peer);
                } else {
                    $separated[] = $peername;
                }
            }
            if ($separated) {
                $others = implode(', ', array_slice($separated, 0, self::OTHERS_CAP))
                    . (count($separated) > self::OTHERS_CAP ? ', …' : '');
                $why[] = ['text' => get_string('auditwhyapart', 'local_groupdist', (object) [
                    'index' => $info['index'],
                    'label' => $info['label'],
                    'others' => $others,
                ]), 'muted' => false, 'warn' => false];
            }
            if ($conflicting) {
                $others = implode(', ', array_slice($conflicting, 0, self::OTHERS_CAP))
                    . (count($conflicting) > self::OTHERS_CAP ? ', …' : '');
                $why[] = ['text' => get_string('auditwhyapartsame', 'local_groupdist', (object) [
                    'index' => $info['index'],
                    'label' => $info['label'],
                    'others' => $others,
                ]), 'muted' => false, 'warn' => true];
            }
        }
        if (!$why) {
            $why[] = ['text' => get_string('auditwhynone', 'local_groupdist'), 'muted' => true, 'warn' => false];
        }

        return [
            'name' => $membername($row),
            'removed' => ((int) $row->userid === 0),
            'outcome' => self::outcome_badge((int) $row->writestatus),
            'why' => $why,
        ];
    }

    /**
     * Localise the snapshot warnings using the snapshot rule labels.
     *
     * @param array $ruleinfo Per-rule display info.
     * @param callable $display Value display mapper fn (ruleindex, value).
     * @return array List of ['message' => ...].
     */
    protected function export_warnings(array $ruleinfo, callable $display): array {
        $formatted = [];
        foreach ((json_decode($this->run->warningsjson, true) ?? []) as $warning) {
            $type = $warning['type'] ?? '';
            $count = (int) ($warning['count'] ?? 0);
            $ruleindex = $warning['rule'] ?? null;
            $label = ($ruleindex !== null && isset($ruleinfo[$ruleindex])) ? $ruleinfo[$ruleindex]['label'] : '';
            $masked = ($ruleindex !== null && isset($ruleinfo[$ruleindex])) ? $ruleinfo[$ruleindex]['masked'] : false;
            $rawvalue = (string) ($warning['value'] ?? '');
            $value = $masked
                ? get_string('auditmaskedvalue', 'local_groupdist')
                : (($ruleindex !== null && isset($ruleinfo[$ruleindex])) ? $display($ruleindex, $rawvalue) : $rawvalue);

            switch ($type) {
                case 'unassigned':
                    $message = get_string('warningunassigned', 'local_groupdist', $count);
                    break;
                case 'affinitysplit':
                    $message = get_string('warningsplit', 'local_groupdist', (object) [
                        'value' => $rawvalue,
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
                        'field' => \local_groupdist\local\fields::get_seats_label(),
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
     * The applier's current name, or the removed marker.
     *
     * @return string The display name.
     */
    protected function applier_name(): string {
        global $DB;

        $userid = (int) $this->run->userid;
        if ($userid > 0) {
            $namefields = implode(',', \core_user\fields::for_name()->get_required_fields());
            if ($user = $DB->get_record('user', ['id' => $userid], 'id,' . $namefields)) {
                return fullname($user);
            }
        }
        return get_string('auditremoved', 'local_groupdist');
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
                return ['label' => get_string('auditoutcomewritten', 'local_groupdist'), 'class' => 'success'];
            case runlog::WRITE_FAILED:
                return ['label' => get_string('auditoutcomefailed', 'local_groupdist'), 'class' => 'danger'];
            case runlog::WRITE_UNASSIGNED:
                return ['label' => get_string('auditoutcomeunassigned', 'local_groupdist'), 'class' => 'warning'];
            case runlog::WRITE_SKIPPED:
                return ['label' => get_string('auditoutcomeskipped', 'local_groupdist'), 'class' => 'secondary'];
            default:
                return ['label' => get_string('auditoutcomeplanned', 'local_groupdist'), 'class' => 'secondary'];
        }
    }
}
