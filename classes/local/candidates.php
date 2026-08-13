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
 * Candidate selection: who takes part in a distribution.
 *
 * One query fetches ids, name fields and the affinity column for every
 * candidate. groups_get_potential_members() is not reusable here: it cannot
 * carry a custom profile field as an extra column (MDL-70456 — mixed parameter
 * types) and it materialises full user records.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class candidates {
    /**
     * Fetch the ordered candidate list.
     *
     * The enrolment predicate comes from core's get_enrolled_join(), which also
     * owns the front-page special case (on SITEID every user counts as enrolled,
     * with no {user_enrolments} rows at all) — do not replace it with an inlined
     * enrolment join.
     *
     * @param options $options The distribution options.
     * @param \core\context\course $context The course context.
     * @return array Ordered map of userid => user record (id, name fields,
     *   idnumber, affinity).
     */
    public static function fetch(options $options, \core\context\course $context): array {
        global $DB;

        // Without the capability, suspended enrolments must never leak
        // (core precedent: group/autogroup.php).
        $onlyactive = $options->onlyactive
            || !has_capability('moodle/course:viewsuspendedusers', $context);

        $enrolledjoin = get_enrolled_join($context, 'u.id', $onlyactive);
        $joins = [$enrolledjoin->joins];
        $wheres = ['u.deleted = 0'];
        if ($enrolledjoin->wheres) {
            $wheres[] = $enrolledjoin->wheres;
        }
        $params = $enrolledjoin->params;

        if ($options->roleid) {
            // Role assignments count at the course context and every parent
            // context, mirroring groups_get_potential_members().
            [$ctxsql, $ctxparams] = $DB->get_in_or_equal(
                $context->get_parent_context_ids(true),
                SQL_PARAMS_NAMED,
                'ctx'
            );
            $joins[] = "JOIN {role_assignments} ra
                             ON ra.userid = u.id AND ra.roleid = :roleid AND ra.contextid {$ctxsql}";
            $params['roleid'] = $options->roleid;
            $params += $ctxparams;
        }

        if ($options->cohortid) {
            $joins[] = 'JOIN {cohort_members} cm ON cm.userid = u.id AND cm.cohortid = :cohortid';
            $params['cohortid'] = $options->cohortid;
        }

        if ($options->ignoregrouped && $options->groupids) {
            // Memberships this same run already wrote (itemid = seed) do not
            // disqualify a candidate, so an interrupted apply sees the same
            // candidate set on retry and resumes the identical plan.
            [$groupsql, $groupparams] = $DB->get_in_or_equal($options->groupids, SQL_PARAMS_NAMED, 'ig');
            $wheres[] = "NOT EXISTS (
                             SELECT 1
                               FROM {groups_members} gm
                              WHERE gm.userid = u.id AND gm.groupid {$groupsql}
                                    AND (gm.component <> 'local_groupdist' OR gm.itemid <> :ownseed))";
            $params += $groupparams;
            $params['ownseed'] = $options->seed;
        }

        $affinityselect = 'NULL AS affinity';
        if ($options->is_native_affinity()) {
            // The column name is validated against the native whitelist in the ruleset.
            $affinityselect = 'u.' . $options->get_affinity_source() . ' AS affinity';
        } else if ($fieldid = $options->get_custom_affinity_fieldid()) {
            $joins[] = 'LEFT JOIN {user_info_data} uid ON uid.userid = u.id AND uid.fieldid = :affinityfieldid';
            $params['affinityfieldid'] = $fieldid;
            // Cast for cross-DB comparability; 255 chars are plenty for grouping.
            $affinityselect = $DB->sql_compare_text('uid.data', 255) . ' AS affinity';
        }

        $namefields = implode(', ', array_map(
            function (string $field): string {
                return 'u.' . $field;
            },
            \core_user\fields::for_name()->get_required_fields()
        ));

        $orderby = self::order_by($options->allocateby);
        $joinsql = implode("\n", $joins);
        $wheresql = implode(' AND ', $wheres);

        // The deleted flag rides along so groups_add_member() can take the
        // record as-is without re-fetching each user.
        return $DB->get_records_sql(
            "SELECT DISTINCT u.id, {$namefields}, u.idnumber, u.deleted, {$affinityselect}
               FROM {user} u
                    {$joinsql}
              WHERE {$wheresql}
           ORDER BY {$orderby}",
            $params
        );
    }

    /**
     * Map an allocateby option to a stable ORDER BY clause.
     *
     * Matches core autogroup's mapping, with u.id as the final tiebreaker so
     * the order (and therefore the seeded shuffle input) is fully stable.
     *
     * @param string $allocateby One of the options::ALLOCATE_* constants.
     * @return string The ORDER BY expression.
     */
    private static function order_by(string $allocateby): string {
        switch ($allocateby) {
            case options::ALLOCATE_FIRSTNAME:
                return 'u.firstname, u.lastname, u.id';
            case options::ALLOCATE_IDNUMBER:
                return 'u.idnumber, u.id';
            default:
                // Random shuffles in the allocator; lastname keeps its input stable.
                return 'u.lastname, u.firstname, u.id';
        }
    }
}
