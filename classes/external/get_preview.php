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

namespace local_groupdist\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_groupdist\local\allocator;
use local_groupdist\local\distribution;
use local_groupdist\local\options;
use local_groupdist\local\profilefields;

/**
 * Preview web service: compute the distribution and return one page of groups.
 *
 * Every call recomputes the full allocation deterministically (same options +
 * seed = same result) and slices the requested group window, so paging needs
 * no server-side state. Nothing is ever written.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_preview extends external_api {
    /** @var int Hard cap on how many groups the preview will ever page through. */
    public const GROUP_CAP = 25;

    /** @var int Largest allowed page size. */
    public const MAX_PAGE = 10;

    /** @var int Sample size of members shown per group. */
    public const MEMBER_SAMPLE = 5;

    /** @var int Most value entries listed per rule in the rules report. */
    public const REPORT_VALUE_CAP = 15;

    /** @var int Most destination group names listed per report entry. */
    public const REPORT_GROUP_CAP = 5;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters The parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'groupids' => new external_value(PARAM_SEQUENCE, 'Comma-separated target group ids'),
            'roleid' => new external_value(PARAM_INT, 'Role filter (0 = any)', VALUE_DEFAULT, 0),
            'cohortid' => new external_value(PARAM_INT, 'Cohort filter (0 = any)', VALUE_DEFAULT, 0),
            'allocateby' => new external_value(PARAM_ALPHA, 'Allocation order', VALUE_DEFAULT, 'random'),
            'ignoregrouped' => new external_value(PARAM_BOOL, 'Skip users already in a selected group', VALUE_DEFAULT, true),
            'onlyactive' => new external_value(PARAM_BOOL, 'Only active enrolments', VALUE_DEFAULT, true),
            'includefuture' => new external_value(PARAM_BOOL, 'Also include future-start enrolments', VALUE_DEFAULT, false),
            'affinityrules' => new external_multiple_structure(
                new external_single_structure([
                    'source' => new external_value(PARAM_ALPHANUMEXT, 'Rule source key'),
                    'mode' => new external_value(PARAM_ALPHA, 'Rule strategy (together/apart)'),
                ]),
                'Affinity rules in priority order',
                VALUE_DEFAULT,
                []
            ),
            'useseats' => new external_value(PARAM_BOOL, 'Respect the seats field', VALUE_DEFAULT, true),
            'overbook' => new external_value(PARAM_INT, 'Overbooking per group', VALUE_DEFAULT, 0),
            'seed' => new external_value(PARAM_INT, 'Deterministic seed'),
            'limitfrom' => new external_value(PARAM_INT, 'Group window offset', VALUE_DEFAULT, 0),
            'limitnum' => new external_value(PARAM_INT, 'Group window size', VALUE_DEFAULT, 5),
        ]);
    }

    /**
     * Compute the preview page.
     *
     * @param int $courseid Course id.
     * @param string $groupids Comma-separated group ids.
     * @param int $roleid Role filter.
     * @param int $cohortid Cohort filter.
     * @param string $allocateby Allocation order.
     * @param bool $ignoregrouped Skip users already in a selected group.
     * @param bool $onlyactive Only active enrolments.
     * @param bool $includefuture Also include future-start enrolments.
     * @param array $affinityrules Affinity rules in priority order.
     * @param bool $useseats Respect the seats field.
     * @param int $overbook Overbooking per group.
     * @param int $seed Deterministic seed.
     * @param int $limitfrom Group window offset.
     * @param int $limitnum Group window size.
     * @return array The preview payload.
     */
    public static function execute(
        int $courseid,
        string $groupids,
        int $roleid,
        int $cohortid,
        string $allocateby,
        bool $ignoregrouped,
        bool $onlyactive,
        bool $includefuture,
        array $affinityrules,
        bool $useseats,
        int $overbook,
        int $seed,
        int $limitfrom,
        int $limitnum
    ): array {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'groupids' => $groupids,
            'roleid' => $roleid,
            'cohortid' => $cohortid,
            'allocateby' => $allocateby,
            'ignoregrouped' => $ignoregrouped,
            'onlyactive' => $onlyactive,
            'includefuture' => $includefuture,
            'affinityrules' => $affinityrules,
            'useseats' => $useseats,
            'overbook' => $overbook,
            'seed' => $seed,
            'limitfrom' => $limitfrom,
            'limitnum' => $limitnum,
        ]);

        // Context derived server-side from the course id, never from a raw contextid.
        $context = \core\context\course::instance($params['courseid']);
        self::validate_context($context);
        if (isguestuser()) {
            throw new \moodle_exception('noguest');
        }
        require_capability('local/groupdist:distribute', $context);

        $options = options::from_array($params);
        foreach ($options->affinityrules->get_rules() as $rule) {
            if (!profilefields::is_allowed($rule['source'], $context)) {
                throw new \invalid_parameter_exception('affinityrules');
            }
        }
        if ($options->cohortid) {
            // Visibility check: a raw cohort id must not become a membership
            // oracle for cohorts the user cannot see.
            require_once($CFG->dirroot . '/cohort/lib.php');
            if (!cohort_get_cohort($options->cohortid, $context)) {
                throw new \invalid_parameter_exception('cohortid');
            }
        }
        $coursegroups = groups_get_all_groups($options->courseid);
        $options->groupids = array_values(array_intersect(
            $options->groupids,
            array_map('intval', array_keys($coursegroups))
        ));

        $distribution = distribution::build($options, $context);
        $totals = $distribution->totals();

        $limitfrom = max(0, $params['limitfrom']);
        $limitnum = min(max(1, $params['limitnum']), self::MAX_PAGE);
        $windowsize = max(0, min($limitnum, self::GROUP_CAP - $limitfrom));
        $window = $windowsize ? array_slice($distribution->groups, $limitfrom, $windowsize) : [];

        $existingsamples = self::fetch_existing_samples($distribution, $window);

        $rules = $options->affinityrules->get_rules();
        $valuemaps = self::build_value_maps($rules, $context);

        $groupspayload = [];
        foreach ($window as $group) {
            $allocated = $distribution->allocation->assignments[$group['id']] ?? [];
            $total = $group['current'] + count($allocated);

            $members = [];
            foreach (array_slice($allocated, 0, self::MEMBER_SAMPLE) as $userid) {
                $user = $distribution->users[$userid];
                $affinities = [];
                foreach ($rules as $i => $rule) {
                    $value = trim((string) ($user->{'affinity' . $i} ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $affinities[] = [
                        'value' => $valuemaps[$i][$value] ?? $value,
                        'apart' => ($rule['mode'] === options::AFFINITY_APART),
                    ];
                }
                $enrolstart = '';
                if (!empty($user->futurestart)) {
                    $enrolstart = get_string('enrolstartbadge', 'local_groupdist', userdate(
                        (int) $user->futurestart,
                        get_string('strftimedatefullshort', 'langconfig')
                    ));
                }
                $members[] = [
                    'fullname' => fullname($user),
                    'affinities' => $affinities,
                    'enrolstart' => $enrolstart,
                    'isnew' => true,
                ];
            }
            foreach ($existingsamples[$group['id']] ?? [] as $user) {
                if (count($members) >= self::MEMBER_SAMPLE) {
                    break;
                }
                $members[] = ['fullname' => fullname($user), 'affinities' => [], 'enrolstart' => '', 'isnew' => false];
            }

            $rulestatus = [];
            foreach ($rules as $i => $rule) {
                $counts = [];
                foreach ($allocated as $userid) {
                    $value = trim((string) ($distribution->users[$userid]->{'affinity' . $i} ?? ''));
                    if ($value !== '') {
                        $counts[$value] = ($counts[$value] ?? 0) + 1;
                    }
                }
                if ($rule['mode'] === options::AFFINITY_TOGETHER) {
                    $ok = count($counts) <= 1;
                    $single = $counts ? array_key_first($counts) : '';
                    $text = $ok
                        ? (string) ($valuemaps[$i][$single] ?? $single)
                        : get_string('rulestatusvalues', 'local_groupdist', count($counts));
                } else {
                    $repeats = 0;
                    foreach ($counts as $count) {
                        $repeats += max(0, $count - 1);
                    }
                    $ok = ($repeats === 0);
                    $text = $ok ? '' : get_string('rulestatusrepeats', 'local_groupdist', $repeats);
                }
                $rulestatus[] = [
                    'index' => $i + 1,
                    'label' => profilefields::get_label($rule['source'], $context),
                    'ok' => $ok,
                    'text' => $text,
                ];
            }

            $seats = $group['seats'];
            $overflow = ($seats !== null) ? max(0, $total - $seats) : 0;
            $denominator = ($seats !== null) ? max(1, $seats + $overflow) : 0;
            $groupspayload[] = [
                'id' => $group['id'],
                'name' => format_string($group['name'], true, ['context' => $context]),
                'location' => (string) ($group['location'] ?? ''),
                'seats' => $seats ?? -1,
                'current' => $group['current'],
                'allocated' => count($allocated),
                'total' => $total,
                'overflow' => $overflow,
                'hasseats' => ($seats !== null && $options->useseats),
                'fillpct' => $denominator ? (int) round(min($total, $seats) / $denominator * 100) : 0,
                'spillpct' => $denominator ? (int) round($overflow / $denominator * 100) : 0,
                'members' => $members,
                'rules' => $rulestatus,
                'hiddencount' => max(0, $total - count($members)),
            ];
        }

        $groupstotal = count($distribution->groups);
        return [
            'totals' => [
                'candidates' => $totals['candidates'],
                'groups' => $totals['groups'],
                'memberships' => $totals['memberships'],
                'unassigned' => $totals['unassigned'],
                'seatstotal' => $totals['seatstotal'],
                'overbooked' => $totals['overbooked'],
                'average' => $totals['groups'] ? format_float($totals['memberships'] / $totals['groups'], 1) : '0',
            ],
            'warnings' => self::format_warnings($distribution, $context, $valuemaps),
            'rulereport' => self::build_rule_report($distribution, $valuemaps, $context),
            'groups' => $groupspayload,
            'locationlabel' => \local_groupdist\local\fields::get_location_label(),
            'total' => $groupstotal,
            'shownmax' => min($groupstotal, self::GROUP_CAP),
            'capped' => $groupstotal > self::GROUP_CAP,
            'fingerprint' => $distribution->fingerprint,
        ];
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure The structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'totals' => new external_single_structure([
                'candidates' => new external_value(PARAM_INT, 'Users taking part'),
                'groups' => new external_value(PARAM_INT, 'Target groups'),
                'memberships' => new external_value(PARAM_INT, 'Memberships the run would create'),
                'unassigned' => new external_value(PARAM_INT, 'Users left without a group'),
                'seatstotal' => new external_value(PARAM_INT, 'Sum of declared seats (-1 when none declared)'),
                'overbooked' => new external_value(PARAM_INT, 'Memberships beyond declared seats'),
                'average' => new external_value(PARAM_TEXT, 'Average new members per group, localised'),
            ]),
            'warnings' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHANUMEXT, 'Warning type'),
                'message' => new external_value(PARAM_TEXT, 'Localised message'),
            ])),
            'rulereport' => new external_multiple_structure(new external_single_structure([
                'index' => new external_value(PARAM_INT, '1-based rule priority'),
                'label' => new external_value(PARAM_TEXT, 'Rule source label'),
                'mode' => new external_value(PARAM_TEXT, 'Localised short mode name'),
                'apart' => new external_value(PARAM_BOOL, 'Whether the rule is keep-apart'),
                'entries' => new external_multiple_structure(new external_single_structure([
                    'value' => new external_value(PARAM_TEXT, 'Display value'),
                    'count' => new external_value(PARAM_INT, 'Allocated holders of the value'),
                    'groups' => new external_value(PARAM_TEXT, 'Destination group names (capped list)'),
                    'flagtext' => new external_value(PARAM_TEXT, 'Split/violation note ("" when clean)'),
                ])),
                'more' => new external_value(PARAM_INT, 'Value entries beyond the cap'),
                'novalue' => new external_value(PARAM_INT, 'Participants without a value in this rule'),
            ])),
            'groups' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Group id'),
                'name' => new external_value(PARAM_TEXT, 'Group name'),
                'location' => new external_value(PARAM_TEXT, 'Location field value ("" when unset)'),
                'seats' => new external_value(PARAM_INT, 'Declared seats (-1 when unset)'),
                'current' => new external_value(PARAM_INT, 'Current member count'),
                'allocated' => new external_value(PARAM_INT, 'New members allocated by this run'),
                'total' => new external_value(PARAM_INT, 'Resulting member count'),
                'overflow' => new external_value(PARAM_INT, 'Members beyond declared seats'),
                'hasseats' => new external_value(PARAM_BOOL, 'Whether the capacity meter applies'),
                'fillpct' => new external_value(PARAM_INT, 'Meter fill percentage'),
                'spillpct' => new external_value(PARAM_INT, 'Meter overbooking percentage'),
                'members' => new external_multiple_structure(new external_single_structure([
                    'fullname' => new external_value(PARAM_TEXT, 'Member name'),
                    'affinities' => new external_multiple_structure(new external_single_structure([
                        'value' => new external_value(PARAM_TEXT, 'Display value'),
                        'apart' => new external_value(PARAM_BOOL, 'Whether the rule is keep-apart'),
                    ]), 'One badge per non-empty rule value, in priority order'),
                    'enrolstart' => new external_value(PARAM_TEXT, 'Future enrolment start note ("" when current)'),
                    'isnew' => new external_value(PARAM_BOOL, 'Whether this run adds the member'),
                ])),
                'rules' => new external_multiple_structure(new external_single_structure([
                    'index' => new external_value(PARAM_INT, '1-based rule priority'),
                    'label' => new external_value(PARAM_TEXT, 'Rule source label'),
                    'ok' => new external_value(PARAM_BOOL, 'Whether the rule holds inside this group'),
                    'text' => new external_value(PARAM_TEXT, 'Status detail ("" when none)'),
                ]), 'Per-rule status over the members this run allocates'),
                'hiddencount' => new external_value(PARAM_INT, 'Members not shown in the sample'),
            ])),
            'locationlabel' => new external_value(PARAM_TEXT, 'Stored display name of the location field'),
            'total' => new external_value(PARAM_INT, 'Total target groups'),
            'shownmax' => new external_value(PARAM_INT, 'Most groups the preview will page through'),
            'capped' => new external_value(PARAM_BOOL, 'Whether the preview cap hides groups'),
            'fingerprint' => new external_value(PARAM_ALPHANUM, 'Plan fingerprint, re-checked at apply time'),
        ]);
    }

    /**
     * Fetch up to MEMBER_SAMPLE existing members (names only) per windowed group.
     *
     * Only groups whose allocated sample leaves room are queried for.
     *
     * @param distribution $distribution The distribution.
     * @param array $window The sliced group entries.
     * @return array Map of groupid => list of user records.
     */
    private static function fetch_existing_samples(distribution $distribution, array $window): array {
        global $DB;

        $need = [];
        foreach ($window as $group) {
            $allocated = count($distribution->allocation->assignments[$group['id']] ?? []);
            if ($group['current'] > 0 && $allocated < self::MEMBER_SAMPLE) {
                $need[$group['id']] = self::MEMBER_SAMPLE - $allocated;
            }
        }
        if (!$need) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($need), SQL_PARAMS_NAMED, 'sg');
        $namefields = implode(', ', array_map(
            function (string $field): string {
                return 'u.' . $field;
            },
            \core_user\fields::for_name()->get_required_fields()
        ));
        $recordset = $DB->get_recordset_sql(
            "SELECT gm.id AS gmid, gm.groupid, u.id, {$namefields}
               FROM {groups_members} gm
               JOIN {user} u ON u.id = gm.userid
              WHERE gm.groupid {$insql} AND u.deleted = 0
           ORDER BY gm.groupid, gm.id",
            $params
        );

        $samples = [];
        foreach ($recordset as $record) {
            $groupid = (int) $record->groupid;
            if (count($samples[$groupid] ?? []) < ($need[$groupid] ?? 0)) {
                $samples[$groupid][] = $record;
            }
        }
        $recordset->close();
        return $samples;
    }

    /**
     * Per-rule maps of raw values to display text.
     *
     * Raw values are shown as-is except where they would be opaque: country
     * codes map to country names, the binary cohort flag to the cohort label.
     *
     * @param array $rules The ruleset entries.
     * @param \core\context\course $context The course context.
     * @return array List (rule index => map of raw value => display text).
     */
    private static function build_value_maps(array $rules, \core\context\course $context): array {
        $valuemaps = [];
        foreach ($rules as $i => $rule) {
            if ($rule['source'] === 'country') {
                $valuemaps[$i] = get_string_manager()->get_list_of_countries(true);
            } else if (\local_groupdist\local\ruleset::source_cohortid($rule['source'])) {
                $valuemaps[$i] = ['1' => profilefields::get_label($rule['source'], $context)];
            } else {
                $valuemaps[$i] = [];
            }
        }
        return $valuemaps;
    }

    /**
     * Global per-rule report: value clusters, destinations and trouble flags.
     *
     * Computed over the full allocation (not the paged window), so it is the
     * proof that each rule worked: which values clustered where, which were
     * split, which keep-apart values had to repeat. Values held by fewer than
     * two allocated members are noise and are skipped; entries are capped with
     * an explicit remainder count — never a silent truncation.
     *
     * @param distribution $distribution The distribution.
     * @param array $valuemaps Per-rule display maps from build_value_maps().
     * @param \core\context\course $context The course context.
     * @return array Report entries, one per rule.
     */
    private static function build_rule_report(
        distribution $distribution,
        array $valuemaps,
        \core\context\course $context
    ): array {
        $rules = $distribution->options->affinityrules->get_rules();
        if (!$rules) {
            return [];
        }

        $groupnames = [];
        foreach ($distribution->groups as $group) {
            $groupnames[$group['id']] = format_string($group['name'], true, ['context' => $context]);
        }

        $violations = [];
        $novalue = [];
        foreach ($distribution->warnings as $warning) {
            if ($warning['type'] === allocator::WARNING_APART) {
                $violations[$warning['rule']][$warning['value']] = $warning['count'];
            } else if ($warning['type'] === allocator::WARNING_NOVALUE) {
                $novalue[$warning['rule']] = $warning['count'];
            }
        }

        $report = [];
        foreach ($rules as $i => $rule) {
            $buckets = [];
            foreach ($distribution->allocation->assignments as $groupid => $userids) {
                foreach ($userids as $userid) {
                    $value = trim((string) ($distribution->users[$userid]->{'affinity' . $i} ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $buckets[$value]['count'] = ($buckets[$value]['count'] ?? 0) + 1;
                    $buckets[$value]['groups'][$groupid] = true;
                }
            }
            $buckets = array_filter($buckets, function (array $bucket): bool {
                return $bucket['count'] >= 2;
            });
            uksort($buckets, function ($a, $b) use ($buckets): int {
                $bysize = $buckets[$b]['count'] <=> $buckets[$a]['count'];
                return $bysize !== 0 ? $bysize : strcmp((string) $a, (string) $b);
            });

            $apart = ($rule['mode'] === options::AFFINITY_APART);
            $entries = [];
            foreach (array_slice($buckets, 0, self::REPORT_VALUE_CAP, true) as $value => $bucket) {
                $names = array_values(array_intersect_key($groupnames, $bucket['groups']));
                $shown = array_slice($names, 0, self::REPORT_GROUP_CAP);
                $groupstext = implode(', ', $shown) . (count($names) > count($shown) ? ', …' : '');
                $flagtext = '';
                if ($apart && !empty($violations[$i][$value])) {
                    $flagtext = get_string('rulereportviolations', 'local_groupdist', $violations[$i][$value]);
                } else if (!$apart && count($names) > 1) {
                    $flagtext = get_string('rulereportsplit', 'local_groupdist', count($names));
                }
                $entries[] = [
                    'value' => (string) ($valuemaps[$i][$value] ?? $value),
                    'count' => $bucket['count'],
                    'groups' => $groupstext,
                    'flagtext' => $flagtext,
                ];
            }

            $report[] = [
                'index' => $i + 1,
                'label' => profilefields::get_label($rule['source'], $context),
                'mode' => $apart
                    ? get_string('modeapart', 'local_groupdist')
                    : get_string('modetogether', 'local_groupdist'),
                'apart' => $apart,
                'entries' => $entries,
                'more' => max(0, count($buckets) - self::REPORT_VALUE_CAP),
                'novalue' => $novalue[$i] ?? 0,
            ];
        }
        return $report;
    }

    /**
     * Localise the distribution's typed warnings.
     *
     * @param distribution $distribution The distribution.
     * @param \core\context\course $context The course context.
     * @param array $valuemaps Per-rule display maps from build_value_maps().
     * @return array List of ['type' => ..., 'message' => ...].
     */
    private static function format_warnings(
        distribution $distribution,
        \core\context\course $context,
        array $valuemaps
    ): array {
        $rules = $distribution->options->affinityrules->get_rules();
        $rulelabel = function (array $warning) use ($rules, $context): string {
            $source = $rules[$warning['rule'] ?? -1]['source'] ?? '';
            return profilefields::get_label($source, $context);
        };
        $formatted = [];
        foreach ($distribution->warnings as $warning) {
            $count = $warning['count'] ?? 0;
            switch ($warning['type']) {
                case distribution::WARNING_NOSEATS:
                    $message = get_string('warningnoseats', 'local_groupdist', (object) [
                        'count' => $count,
                        'field' => \local_groupdist\local\fields::get_seats_label(),
                    ]);
                    break;
                case distribution::WARNING_COMMSLOW:
                    $message = get_string('warningcommslow', 'local_groupdist');
                    break;
                case allocator::WARNING_UNASSIGNED:
                    $message = get_string('warningunassigned', 'local_groupdist', $count);
                    break;
                case allocator::WARNING_SPLIT:
                    $message = get_string('warningsplit', 'local_groupdist', (object) [
                        'value' => $warning['value'],
                        'count' => $count,
                    ]);
                    break;
                case allocator::WARNING_APART:
                    $rawvalue = (string) $warning['value'];
                    $message = get_string('warningapart', 'local_groupdist', (object) [
                        'value' => $valuemaps[$warning['rule']][$rawvalue] ?? $rawvalue,
                        'field' => $rulelabel($warning),
                        'count' => $count,
                    ]);
                    break;
                case allocator::WARNING_NOVALUE:
                    $message = get_string('warningnovalue', 'local_groupdist', (object) [
                        'count' => $count,
                        'field' => $rulelabel($warning),
                    ]);
                    break;
                case allocator::WARNING_CONTRADICTION:
                    $message = get_string('warningcontradiction', 'local_groupdist', (object) [
                        'count' => $count,
                        'field' => $rulelabel($warning),
                    ]);
                    break;
                default:
                    $message = $warning['type'];
            }
            $formatted[] = ['type' => $warning['type'], 'message' => $message];
        }
        return $formatted;
    }
}
