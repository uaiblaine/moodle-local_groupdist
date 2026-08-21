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
 * Enumeration and authorization of the affinity rule sources.
 *
 * Native user columns are always offered. Custom profile fields follow core's
 * visibility semantics (profile_field_base::is_visible(), listing case).
 * Cohorts follow cohort_get_cohort()'s parent-context and visibility rules, so
 * a hidden cohort id can never become a membership oracle.
 *
 * Course groups start from groups_get_all_groups(), the same call the plugin
 * already validates submitted DESTINATION groups against in six places — so a
 * group SOURCE is never more permissive than a group destination in the same
 * request — but the visibility decision is then made HERE rather than trusted
 * from that helper, which fails open on a cold cache. get_source_groups()
 * carries the mechanism; docs/mockups/rule-source-groups.html carries the
 * design decision.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profilefields {
    /**
     * The profile-field sources the acting user may rule on, as select options.
     *
     * Custom profile fields are filtered per core's listing semantics:
     * everyone sees PROFILE_VISIBLE_ALL fields; teacher-visible fields need
     * moodle/site:viewuseridentity at the course; private/hidden fields need
     * moodle/user:viewalldetails. The preview prints each candidate's value,
     * so offering a more restricted field would leak it. Cohorts are handled
     * separately (they can number in the thousands and are picked through a
     * bounded list or a search — never enumerated here).
     *
     * @param \core\context\course $context The course context.
     * @return array Option key => label. Keys use the ruleset source
     *   encoding: a native column name or profile_<id>.
     */
    public static function get_fields(\core\context\course $context): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $result = [];
        foreach (options::NATIVE_AFFINITY_FIELDS as $field) {
            $result[$field] = get_string($field);
        }

        $viewall = has_capability('moodle/user:viewalldetails', $context);
        $viewidentity = has_capability('moodle/site:viewuseridentity', $context);
        foreach (profile_get_custom_fields() as $field) {
            switch ((int) $field->visible) {
                case PROFILE_VISIBLE_ALL:
                    $allowed = true;
                    break;
                case PROFILE_VISIBLE_TEACHERS:
                    $allowed = $viewidentity;
                    break;
                default:
                    // PROFILE_VISIBLE_PRIVATE and PROFILE_VISIBLE_NONE.
                    $allowed = $viewall;
            }
            if (!$allowed) {
                continue;
            }
            $result['profile_' . (int) $field->id] = self::plain($field->name, \core\context\system::instance());
        }
        return $result;
    }

    /**
     * The course groups the acting user may rule on, as id => name.
     *
     * Unlike cohorts, groups ARE enumerated: the never-enumerate rule exists
     * because cohorts are site-level and platforms carry thousands, while
     * groups are course-bounded and step 1 already loads every group record of
     * the course to validate the submitted destinations. The picker is still
     * bounded — see options_form::GROUP_MENU_LIMIT — but that bound is about
     * usability, not disclosure.
     *
     * The rule is: a group may be a source only when the actor can already read
     * its WHOLE membership, because that is exactly what a source discloses —
     * the preview paints a badge on every member. Per visibility level:
     * ALL is readable by anyone; MEMBERS by a member (core lets a member of a
     * MEMBERS group see the full list); OWN by nobody but a viewhiddengroups
     * holder, since core shows a plain member only their own row
     * (visibility::sql_member_visibility_where()); NONE likewise.
     *
     * **Every arm is stated here rather than delegated to
     * groups_get_all_groups().** That helper does apply the same predicate in
     * its SQL path, but it short-circuits to an unfiltered MUC read whenever
     * core_group\visibility::can_view_all_groups() says the course has no
     * hidden groups — and that check FAILS OPEN on a cold cache: it re-reads
     * the cache after warming it and then discards the value, so a missing
     * entry evaluates `false > 0` and reports "nothing hidden" (grouplib
     * visibility.php, identical on 405/501/502/503-dev). Measured on m501: with
     * the core/coursehiddengroups entry purged, one call returns every group of
     * the course including NONE-visibility ones. Anything that merely subtracts
     * OWN from that result therefore leaks a hidden group's name — and, through
     * is_allowed(), its whole membership — for one call after every cache
     * purge. Core's own group/index.php is not exposed because it passes
     * $withmembers, which skips the shortcut; this helper cannot.
     *
     * Do NOT reach for groups_get_group() (no course check, no visibility
     * check — a cross-course membership oracle) or groups_group_visible()
     * (that tests the activity GROUPMODE, a different axis from the
     * visibility column).
     *
     * @param \core\context\course $context The course context.
     * @param bool $escape Whether to HTML-escape the names. The default is the
     *   plain spelling, which is what the rule builder's data attribute and the
     *   web service need; a moodleform validation error is a raw sink and wants
     *   true. Same switch, and the same reason, as core's
     *   field_controller::get_formatted_name().
     * @return array Group id => formatted name, ordered by name.
     */
    public static function get_source_groups(\core\context\course $context, bool $escape = false): array {
        global $DB, $USER;

        /* Deliberately not memoised. This is one indexed query (two at most),
           so the worst case is one cheap call per rule; a static keyed by
           course id is the shape that survives resetAfterTest() holding a
           stale listing for a REUSED course id, which only ever shows up as a
           mystery failure in someone else's test. */
        $courseid = (int) $context->instanceid;
        $viewhidden = has_capability('moodle/course:viewhiddengroups', $context);
        $mine = null;

        $result = [];
        foreach (groups_get_all_groups($courseid) as $group) {
            $visibility = (int) $group->visibility;
            if (!$viewhidden && $visibility !== GROUPS_VISIBILITY_ALL) {
                if ($visibility !== GROUPS_VISIBILITY_MEMBERS) {
                    // OWN and NONE: never a source without the capability.
                    continue;
                }
                if ($mine === null) {
                    /* Membership is a fact about the course, so it is asked of
                       the table directly — the same reasoning as
                       auditreader::live_group_ids(). Fetched at most once, and
                       only when a MEMBERS group is actually in play. */
                    $mine = $DB->get_records_sql_menu(
                        "SELECT gm.groupid, 1 AS ismember
                           FROM {groups_members} gm
                           JOIN {groups} g ON g.id = gm.groupid
                          WHERE g.courseid = :courseid AND gm.userid = :userid",
                        ['courseid' => $courseid, 'userid' => $USER->id]
                    );
                }
                if (!isset($mine[(int) $group->id])) {
                    continue;
                }
            }
            $result[(int) $group->id] = $escape
                ? format_string($group->name, true, ['context' => $context])
                : self::plain($group->name, $context);
        }
        \core_collator::asort($result);
        return $result;
    }

    /**
     * Whether a given source key is offered to the acting user.
     *
     * Used to validate submitted rules server-side (form, web service, apply).
     * Cohort sources are checked through cohort_get_cohort() — the same
     * parent-context and visibility rules the picker menu implied. Group
     * sources are checked against get_source_groups(), which is likewise the
     * exact set the picker offered, so the picker can never offer what this
     * rejects.
     *
     * @param string $key The source encoding.
     * @param \core\context\course $context The course context.
     * @return bool True when allowed.
     */
    public static function is_allowed(string $key, \core\context\course $context): bool {
        global $CFG;

        if ($cohortid = ruleset::source_cohortid($key)) {
            require_once($CFG->dirroot . '/cohort/lib.php');
            return (bool) cohort_get_cohort($cohortid, $context);
        }
        if ($groupid = ruleset::source_groupid($key)) {
            return array_key_exists($groupid, self::get_source_groups($context));
        }
        return array_key_exists($key, self::get_fields($context));
    }

    /**
     * Format an admin-set name for output, unescaped.
     *
     * Every consumer of these labels escapes for itself — the rule builder
     * prints them through Mustache double stashes and rules.js writes search
     * results with textContent — so the default escaping would show a cohort
     * named "Ciencias & Letras" as "Ciencias &amp; Letras" on the first screen
     * of the flow. The context is explicit rather than left to fall back on
     * $PAGE->context, which is not merely tidier: get_label() is reached from
     * runlog::create() and so runs inside the adhoc apply task, where the
     * fallback would throw.
     *
     * Note what does NOT come through here: options_form's cohortid select.
     * Core renders a select's options through a TRIPLE stash
     * (lib/form/templates/element-select.mustache), so that one label must
     * stay escaped and builds its own format_string call.
     *
     * @param string $name The stored name.
     * @param \core\context $context The context to format in.
     * @return string The formatted name, not HTML-escaped.
     */
    private static function plain(string $name, \core\context $context): string {
        return format_string($name, true, ['context' => $context, 'escape' => false]);
    }

    /**
     * Human-readable label of a source key.
     *
     * @param string $key The source encoding.
     * @param \core\context\course $context The course context.
     * @return string The label ('' when unknown or not visible).
     */
    public static function get_label(string $key, \core\context\course $context): string {
        global $CFG, $DB;

        if ($cohortid = ruleset::source_cohortid($key)) {
            require_once($CFG->dirroot . '/cohort/lib.php');
            // The authorization helper returns a partial record (id, contextid,
            // visible) — the name needs its own lookup.
            if (!$cohort = cohort_get_cohort($cohortid, $context)) {
                return '';
            }
            $name = (string) $DB->get_field('cohort', 'name', ['id' => $cohortid]);
            $cohortcontext = \core\context::instance_by_id($cohort->contextid);
            return get_string('cohortsourcelabel', 'local_groupdist', self::plain($name, $cohortcontext));
        }
        if ($groupid = ruleset::source_groupid($key)) {
            // Reached from runlog::create() inside the adhoc apply task, so the
            // name is formatted in the course context passed in rather than any
            // $PAGE fallback. An unresolvable group returns '' exactly as an
            // unresolvable cohort does.
            $name = self::get_source_groups($context)[$groupid] ?? '';
            return $name === '' ? '' : get_string('groupsourcelabel', 'local_groupdist', $name);
        }
        return self::get_fields($context)[$key] ?? '';
    }
}
