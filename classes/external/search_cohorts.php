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

/**
 * Cohort search for the affinity rule builder.
 *
 * Platforms can carry thousands of cohorts, so the builder never enumerates
 * them: above a small threshold the cohort picker becomes this search. The
 * result set follows cohort_get_available_cohorts() — the same parent-context
 * and visibility rules the rule validation applies via cohort_get_cohort() —
 * so nothing is offered here that would be rejected on submit.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_cohorts extends external_api {
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
     * Search the cohorts available in the course context.
     *
     * @param int $courseid Course id.
     * @param string $query Search text.
     * @return array The matching cohorts as rule source options.
     */
    public static function execute(int $courseid, string $query): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

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

        $cohorts = [];
        $matches = cohort_get_available_cohorts($context, 0, 0, self::LIMIT, trim($params['query']));
        foreach ($matches as $cohort) {
            $cohorts[] = [
                'value' => 'cohort_' . (int) $cohort->id,
                // Escaped by the client (rules.js writes it with textContent).
                'label' => format_string($cohort->name, true, [
                    'context' => \core\context::instance_by_id($cohort->contextid),
                    'escape' => false,
                ]),
            ];
        }
        return ['cohorts' => $cohorts];
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure The structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cohorts' => new external_multiple_structure(new external_single_structure([
                'value' => new external_value(PARAM_ALPHANUMEXT, 'Rule source key (cohort_<id>)'),
                'label' => new external_value(PARAM_TEXT, 'Cohort name'),
            ]), 'Matches, capped at 20'),
        ]);
    }
}
