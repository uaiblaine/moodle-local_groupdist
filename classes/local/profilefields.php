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
 * Enumeration of the user fields offered as affinity ("group by") fields.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profilefields {
    /**
     * The affinity fields the acting user may group by, as select options.
     *
     * Native user columns are always offered. Custom profile fields follow
     * core's visibility semantics (profile_field_base::is_visible(), listing
     * case): everyone sees PROFILE_VISIBLE_ALL fields; teacher-visible fields
     * need moodle/site:viewuseridentity at the course; private/hidden fields
     * need moodle/user:viewalldetails. The preview prints each candidate's
     * value, so offering a more restricted field would leak it.
     *
     * @param \core\context\course $context The course context.
     * @return array Option key => label. Keys use the options::affinityfield
     *   encoding: '' (do not group), a native column name, or profile_<id>.
     */
    public static function get_available(\core\context\course $context): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $result = ['' => get_string('affinitynone', 'local_groupdist')];
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
            $result['profile_' . (int) $field->id] = format_string($field->name);
        }
        return $result;
    }

    /**
     * Whether a given affinity field key is offered to the acting user.
     *
     * Used to validate submitted options server-side (form, web service, apply).
     *
     * @param string $key The affinityfield encoding.
     * @param \core\context\course $context The course context.
     * @return bool True when allowed.
     */
    public static function is_allowed(string $key, \core\context\course $context): bool {
        return array_key_exists($key, self::get_available($context));
    }

    /**
     * Human-readable label of an affinity field key.
     *
     * @param string $key The affinityfield encoding.
     * @param \core\context\course $context The course context.
     * @return string The label ('' when unknown).
     */
    public static function get_label(string $key, \core\context\course $context): string {
        return self::get_available($context)[$key] ?? '';
    }
}
