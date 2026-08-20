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
     * Whether a given source key is offered to the acting user.
     *
     * Used to validate submitted rules server-side (form, web service, apply).
     * Cohort sources are checked through cohort_get_cohort() — the same
     * parent-context and visibility rules the picker menu implied.
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
        return self::get_fields($context)[$key] ?? '';
    }
}
