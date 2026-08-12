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
use core_group\customfield\group_handler;
use local_groupdist\output\bulkedit_page;

/**
 * Chunked save of group custom field values from the bulk edit table.
 *
 * Payload discipline: the client sends only CHANGED cells and slices them
 * into sequential requests; this end enforces a hard cap per call
 * ({@see self::MAX_CHANGES}) so no single request can grow unbounded no
 * matter how many groups and fields a course carries.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_group_fields extends external_api {
    /** @var int Hard cap of changed cells per call — the client chunks below this. */
    public const MAX_CHANGES = 200;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters The parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'changes' => new external_multiple_structure(
                new external_single_structure([
                    'groupid' => new external_value(PARAM_INT, 'Group id'),
                    'shortname' => new external_value(PARAM_ALPHANUMEXT, 'Custom field shortname'),
                    'value' => new external_value(PARAM_RAW, 'New value (normalised per field type server-side)'),
                ]),
                'Changed cells only'
            ),
        ]);
    }

    /**
     * Save the changed cells.
     *
     * @param int $courseid The course id.
     * @param array $changes The changed cells.
     * @return array Per-change results.
     */
    public static function execute(int $courseid, array $changes): array {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'changes' => $changes,
        ]);

        if (count($params['changes']) > self::MAX_CHANGES) {
            throw new \invalid_parameter_exception('Too many changes in one call (max ' . self::MAX_CHANGES . ')');
        }

        $context = \core\context\course::instance($params['courseid']);
        self::validate_context($context);
        if (isguestuser()) {
            throw new \moodle_exception('noguest');
        }
        require_capability('moodle/course:managegroups', $context);

        // Editable fields, keyed by shortname (inline-editable types only).
        $columns = [];
        foreach (bulkedit_page::get_field_columns() as $column) {
            if (empty($column['isreadonly'])) {
                $columns[$column['shortname']] = $column;
            }
        }
        $coursegroups = groups_get_all_groups($params['courseid']);

        // Normalise and bucket the changes per group.
        $bygroup = [];
        foreach ($params['changes'] as $change) {
            $groupid = (int) $change['groupid'];
            $shortname = $change['shortname'];
            if (!isset($coursegroups[$groupid])) {
                throw new \invalid_parameter_exception('Group not in course: ' . $groupid);
            }
            if (!isset($columns[$shortname])) {
                throw new \invalid_parameter_exception('Field not inline-editable: ' . $shortname);
            }
            $bygroup[$groupid]['customfield_' . $shortname] =
                self::normalise_value($columns[$shortname], (string) $change['value']);
        }

        $handler = group_handler::create();
        $results = [];
        foreach ($bygroup as $groupid => $properties) {
            $handler->instance_form_save((object) (['id' => $groupid] + $properties));
            foreach ($properties as $element => $value) {
                $shortname = substr($element, strlen('customfield_'));
                $results[] = [
                    'groupid' => $groupid,
                    'shortname' => $shortname,
                    'value' => (string) $value,
                ];
            }
        }

        return ['saved' => $results];
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure The structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'saved' => new external_multiple_structure(new external_single_structure([
                'groupid' => new external_value(PARAM_INT, 'Group id'),
                'shortname' => new external_value(PARAM_ALPHANUMEXT, 'Custom field shortname'),
                'value' => new external_value(PARAM_RAW, 'The normalised value that was stored'),
            ])),
        ]);
    }

    /**
     * Normalise a submitted value for its field type.
     *
     * @param array $column The column descriptor.
     * @param string $value The raw submitted value.
     * @return string|int|float The value in the shape instance_form_save expects.
     */
    private static function normalise_value(array $column, string $value) {
        if ($column['isnumber']) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                // Number fields treat '' as unset.
                return '';
            }
            $number = unformat_float($trimmed, true);
            if ($number === false || $number === null || $number < 0) {
                throw new \invalid_parameter_exception('Invalid number for ' . $column['shortname']);
            }
            return $number;
        }
        if ($column['isselect']) {
            $index = (int) $value;
            if ($index < 0 || $index > count($column['options'])) {
                throw new \invalid_parameter_exception('Invalid option for ' . $column['shortname']);
            }
            return $index;
        }
        if ($column['ischeckbox']) {
            return $value ? 1 : 0;
        }
        // Text: strip tags and control characters, like the form would.
        return clean_param($value, PARAM_TEXT);
    }
}
