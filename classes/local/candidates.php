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
 * One query fetches ids, name fields and the native affinity columns for
 * every candidate; custom profile field rules add one bulk lookup each.
 * groups_get_potential_members() is not reusable here: it cannot
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
     *   idnumber, one affinityN column per rule).
     */
    public static function fetch(options $options, \core\context\course $context): array {
        global $DB;

        /* Without the capability, non-current enrolments must never leak
           (core precedent: group/autogroup.php) — which also forces the
           future-start option off: future enrolments are not current. The
           option is likewise inert when the only-active filter is off, since
           future enrolments are already included then. */
        $canviewsuspended = has_capability('moodle/course:viewsuspendedusers', $context);
        $onlyactive = $options->onlyactive || !$canviewsuspended;
        $includefuture = $options->includefuture && $onlyactive && $canviewsuspended;

        $enrolledjoin = get_enrolled_join($context, 'u.id', $onlyactive && !$includefuture);
        $joins = [$enrolledjoin->joins];
        $wheres = ['u.deleted = 0'];
        if ($enrolledjoin->wheres) {
            $wheres[] = $enrolledjoin->wheres;
        }
        $params = $enrolledjoin->params;

        if ($includefuture && $options->courseid != SITEID) {
            /* Active-or-future filter over the plain enrolment join. This
               inlines (and must stay in agreement with) the only-active
               predicate of get_enrolled_join(), relaxing only the timestart
               half; suspended and expired enrolments stay excluded. SITEID is
               exempt like in the helper — on the frontpage everyone counts as
               enrolled and nobody holds a {user_enrolments} row. */
            $now = round(time(), -2); // Same rounding core uses (helps DB caching).
            $wheres[] = "EXISTS (
                             SELECT 1
                               FROM {user_enrolments} fue
                               JOIN {enrol} fe ON fe.id = fue.enrolid AND fe.courseid = :fcourseid
                              WHERE fue.userid = u.id
                                    AND fue.status = :fueactive AND fe.status = :feenabled
                                    AND ((fue.timestart < :fnow1 AND (fue.timeend = 0 OR fue.timeend > :fnow2))
                                         OR fue.timestart >= :fnow3))";
            $params['fcourseid'] = $options->courseid;
            $params['fueactive'] = ENROL_USER_ACTIVE;
            $params['feenabled'] = ENROL_INSTANCE_ENABLED;
            $params['fnow1'] = $now;
            $params['fnow2'] = $now;
            $params['fnow3'] = $now;
        }

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

        // One value column per rule (affinity0, affinity1, ...). Native sources
        // are free columns on {user}; custom profile fields are bulk-fetched
        // per rule after the base query — one indexed lookup each, so there is
        // no join fan-out as the rule count grows.
        $affinityselects = [];
        $profilerules = [];
        $cohortrules = [];
        foreach ($options->affinityrules->get_rules() as $i => $rule) {
            $kind = ruleset::source_kind($rule['source']);
            if ($kind === ruleset::KIND_NATIVE) {
                // The column name is validated against the native whitelist in the ruleset.
                $affinityselects[] = 'u.' . $rule['source'] . ' AS affinity' . $i;
            } else {
                $affinityselects[] = 'NULL AS affinity' . $i;
                if ($kind === ruleset::KIND_PROFILE) {
                    $profilerules[$i] = ruleset::source_profile_fieldid($rule['source']);
                } else {
                    $cohortrules[$i] = ruleset::source_cohortid($rule['source']);
                }
            }
        }
        $affinitysql = $affinityselects ? (', ' . implode(', ', $affinityselects)) : '';

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
        $users = $DB->get_records_sql(
            "SELECT DISTINCT u.id, {$namefields}, u.idnumber, u.deleted{$affinitysql}
               FROM {user} u
                    {$joinsql}
              WHERE {$wheresql}
           ORDER BY {$orderby}",
            $params
        );

        foreach ($profilerules as $i => $fieldid) {
            if (!$users) {
                break;
            }
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED, 'pf');
            // Cast for cross-DB comparability; 255 chars are plenty for grouping.
            $data = $DB->get_records_sql_menu(
                'SELECT uid.userid, ' . $DB->sql_compare_text('uid.data', 255) . ' AS data
                   FROM {user_info_data} uid
                  WHERE uid.fieldid = :pffieldid AND uid.userid ' . $insql,
                $inparams + ['pffieldid' => $fieldid]
            );
            foreach ($users as $user) {
                $user->{'affinity' . $i} = $data[$user->id] ?? null;
            }
        }

        foreach ($cohortrules as $i => $cohortid) {
            if (!$users) {
                break;
            }
            // Binary source: '1' for members, empty otherwise. Keep-apart then
            // separates cohort mates pairwise (clique semantics without ever
            // materialising edges); keep-together clusters them.
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED, 'cs');
            $members = $DB->get_records_sql_menu(
                'SELECT cm.userid, 1 AS member
                   FROM {cohort_members} cm
                  WHERE cm.cohortid = :cscohortid AND cm.userid ' . $insql,
                $inparams + ['cscohortid' => $cohortid]
            );
            foreach ($users as $user) {
                $user->{'affinity' . $i} = isset($members[$user->id]) ? '1' : null;
            }
        }

        if ($includefuture && $users && $options->courseid != SITEID) {
            /* Display-only marker (never an allocator input, so it stays out
               of the fingerprint): candidates whose only qualifying enrolment
               starts in the future carry its earliest start timestamp. */
            $now = round(time(), -2);
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED, 'fs');
            $starts = $DB->get_records_sql_menu(
                "SELECT fue.userid, MIN(fue.timestart) AS starts
                   FROM {user_enrolments} fue
                   JOIN {enrol} fe ON fe.id = fue.enrolid AND fe.courseid = :fscourseid
                  WHERE fue.status = :fsactive AND fe.status = :fsenabled
                        AND fue.timestart >= :fsnow AND fue.userid {$insql}
               GROUP BY fue.userid",
                $inparams + [
                    'fscourseid' => $options->courseid,
                    'fsactive' => ENROL_USER_ACTIVE,
                    'fsenabled' => ENROL_INSTANCE_ENABLED,
                    'fsnow' => $now,
                ]
            );
            if ($starts) {
                [$cwsql, $cwparams] = $DB->get_in_or_equal(array_keys($starts), SQL_PARAMS_NAMED, 'cw');
                $current = $DB->get_records_sql_menu(
                    "SELECT DISTINCT cue.userid, 1 AS incurrent
                       FROM {user_enrolments} cue
                       JOIN {enrol} ce ON ce.id = cue.enrolid AND ce.courseid = :cwcourseid
                      WHERE cue.status = :cwactive AND ce.status = :cwenabled
                            AND cue.timestart < :cwnow1 AND (cue.timeend = 0 OR cue.timeend > :cwnow2)
                            AND cue.userid {$cwsql}",
                    $cwparams + [
                        'cwcourseid' => $options->courseid,
                        'cwactive' => ENROL_USER_ACTIVE,
                        'cwenabled' => ENROL_INSTANCE_ENABLED,
                        'cwnow1' => $now,
                        'cwnow2' => $now,
                    ]
                );
                foreach ($starts as $userid => $start) {
                    if (!isset($current[$userid])) {
                        $users[$userid]->futurestart = (int) $start;
                    }
                }
            }
        }
        return $users;
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
