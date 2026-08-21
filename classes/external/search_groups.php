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
use local_groupdist\local\profilefields;

/**
 * Course group search for the affinity rule builder.
 *
 * Groups are course-bounded, so unlike cohorts they are enumerated server-side
 * — the options form already holds the whole list to validate this run's
 * destination groups. The picker is nonetheless bounded for usability: past
 * options_form::GROUP_MENU_LIMIT a menu of every group in the course is worse
 * to use than a search box, and this is that search.
 *
 * The result set comes from profilefields::get_source_groups(), which is the
 * SAME helper profilefields::is_allowed() validates a submitted rule against,
 * so this can never offer a group the submit side would reject. Filtering is
 * done in PHP over that already-authorized list rather than in SQL, which is
 * what keeps the two in step; a second query with its own predicates is how
 * a picker and its validator drift apart.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_groups extends external_api {
    /** @var int Most matches returned per call. */
    public const LIMIT = 20;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters The parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'query' => new external_value(PARAM_RAW, 'Search text', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Search the course groups usable as a rule source.
     *
     * @param int $courseid Course id.
     * @param string $query Search text.
     * @return array The matching groups as rule source options.
     */
    public static function execute(int $courseid, string $query): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'query' => $query,
        ]);

        // Context derived server-side from the course id, never from a raw contextid.
        $context = \core\context\course::instance($params['courseid']);
        self::validate_context($context);
        if (isguestuser()) {
            throw new \moodle_exception('noguest');
        }
        require_capability('local/groupdist:distribute', $context);

        $needle = \core_text::strtolower(trim($params['query']));
        $groups = [];
        foreach (profilefields::get_source_groups($context) as $id => $name) {
            if ($needle !== '' && \core_text::strpos(\core_text::strtolower($name), $needle) === false) {
                continue;
            }
            $groups[] = [
                'value' => 'group_' . $id,
                // Already plain: get_source_groups() formats with escape =>
                // false because the client writes matches with textContent.
                'label' => $name,
            ];
            if (count($groups) >= self::LIMIT) {
                break;
            }
        }
        return ['groups' => $groups];
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure The structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'groups' => new external_multiple_structure(new external_single_structure([
                'value' => new external_value(PARAM_ALPHANUMEXT, 'Rule source key (group_<id>)'),
                'label' => new external_value(PARAM_TEXT, 'Group name'),
            ]), 'Matches, capped at 20'),
        ]);
    }
}
