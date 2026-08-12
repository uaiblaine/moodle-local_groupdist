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
            'affinityfield' => new external_value(PARAM_ALPHANUMEXT, 'Affinity field key', VALUE_DEFAULT, ''),
            'affinitymode' => new external_value(PARAM_ALPHA, 'Affinity strategy', VALUE_DEFAULT, 'together'),
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
     * @param string $affinityfield Affinity field key.
     * @param string $affinitymode Affinity strategy.
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
        string $affinityfield,
        string $affinitymode,
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
            'affinityfield' => $affinityfield,
            'affinitymode' => $affinitymode,
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
        if (!profilefields::is_allowed($options->affinityfield, $context)) {
            throw new \invalid_parameter_exception('affinityfield');
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

        $countries = ($options->affinityfield === 'country')
            ? get_string_manager()->get_list_of_countries(true)
            : [];

        $groupspayload = [];
        foreach ($window as $group) {
            $allocated = $distribution->allocation->assignments[$group['id']] ?? [];
            $total = $group['current'] + count($allocated);

            $members = [];
            foreach (array_slice($allocated, 0, self::MEMBER_SAMPLE) as $userid) {
                $user = $distribution->users[$userid];
                $affinityvalue = trim((string) ($user->affinity ?? ''));
                $members[] = [
                    'fullname' => fullname($user),
                    'affinity' => $countries[$affinityvalue] ?? $affinityvalue,
                    'isnew' => true,
                ];
            }
            foreach ($existingsamples[$group['id']] ?? [] as $user) {
                if (count($members) >= self::MEMBER_SAMPLE) {
                    break;
                }
                $members[] = ['fullname' => fullname($user), 'affinity' => '', 'isnew' => false];
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
            'warnings' => self::format_warnings($distribution, $context),
            'groups' => $groupspayload,
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
                    'affinity' => new external_value(PARAM_TEXT, 'Affinity field value ("" when none)'),
                    'isnew' => new external_value(PARAM_BOOL, 'Whether this run adds the member'),
                ])),
                'hiddencount' => new external_value(PARAM_INT, 'Members not shown in the sample'),
            ])),
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
     * Localise the distribution's typed warnings.
     *
     * @param distribution $distribution The distribution.
     * @param \core\context\course $context The course context.
     * @return array List of ['type' => ..., 'message' => ...].
     */
    private static function format_warnings(distribution $distribution, \core\context\course $context): array {
        $fieldlabel = profilefields::get_label($distribution->options->affinityfield, $context);
        $formatted = [];
        foreach ($distribution->warnings as $warning) {
            $count = $warning['count'] ?? 0;
            switch ($warning['type']) {
                case distribution::WARNING_NOSEATS:
                    $message = get_string('warningnoseats', 'local_groupdist', $count);
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
                    $message = get_string('warningapart', 'local_groupdist', (object) [
                        'value' => $warning['value'],
                        'count' => $count,
                    ]);
                    break;
                case allocator::WARNING_NOVALUE:
                    $message = get_string('warningnovalue', 'local_groupdist', (object) [
                        'count' => $count,
                        'field' => $fieldlabel,
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
