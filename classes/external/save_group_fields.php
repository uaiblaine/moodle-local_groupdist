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
use core_customfield\data_controller;
use core_group\customfield\group_handler;
use local_groupdist\local\distribution;
use local_groupdist\local\fields;
use local_groupdist\output\bulkedit_page;

/**
 * Chunked save of group custom field values from the bulk edit table.
 *
 * The client sends only changed cells, in sequential chunks; this end caps
 * each call at {@see self::MAX_CHANGES} cells, whatever the size of the course.
 *
 * Each cell is validated as core's group form would validate that field
 * ({@see self::validate_cell()}). A cell that fails is reported in 'errors'
 * and left unwritten; the valid cells of the same call are still saved. A
 * malformed request (a group of another course or one the caller may not
 * see, a field that is not inline-editable, an unknown select option) still
 * fails the whole call.
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
     * @return array Keys 'saved' (the cells written) and 'errors' (the cells
     *   refused, with the message core's group form would show).
     */
    public static function execute(int $courseid, array $changes): array {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
        require_once($CFG->libdir . '/formslib.php');

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
        /* The groups the bulk edit page lists, by the same rule; never
           groups_get_all_groups(), which returns hidden groups on a cold cache
           ({@see distribution::get_destination_groups()}). */
        $coursegroups = distribution::get_destination_groups($context);

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
            $bygroup[$groupid][$shortname] = self::normalise_value($columns[$shortname], (string) $change['value']);
        }

        $handler = group_handler::create();
        $instances = $handler->get_instances_data(array_keys($bygroup), true);
        $saved = [];
        $errors = [];
        /* One group at a time, validating right before saving, so a field with
           unique values sees the cells this call already wrote for earlier groups. */
        foreach ($bygroup as $groupid => $cells) {
            $controllers = [];
            foreach ($instances[$groupid] ?? [] as $data) {
                $controllers[$data->get_field()->get('shortname')] = $data;
            }
            $properties = [];
            foreach ($cells as $shortname => $value) {
                $message = self::validate_cell($columns[$shortname], $value, $controllers[$shortname] ?? null);
                if ($message !== null) {
                    $errors[] = ['groupid' => $groupid, 'shortname' => (string) $shortname, 'message' => $message];
                } else {
                    $properties['customfield_' . $shortname] = $value;
                }
            }
            if (!$properties) {
                continue;
            }
            // Only the changed customfield_* properties are set: the data
            // controller skips a field whose property is absent, so the
            // group's other values stay as they are.
            $handler->instance_form_save((object) (['id' => $groupid] + $properties));
            foreach ($properties as $element => $value) {
                $saved[] = [
                    'groupid' => $groupid,
                    'shortname' => substr($element, strlen('customfield_')),
                    'value' => (string) $value,
                ];
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
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
            'errors' => new external_multiple_structure(new external_single_structure([
                'groupid' => new external_value(PARAM_INT, 'Group id'),
                'shortname' => new external_value(PARAM_ALPHANUMEXT, 'Custom field shortname'),
                // PARAM_RAW like core's external_warnings: a lang pack may put
                // markup in these strings, and the client writes it as text.
                'message' => new external_value(PARAM_RAW, 'Why the cell was not saved'),
            ]), 'Cells refused by validation and left unwritten'),
        ]);
    }

    /**
     * Normalise a submitted value for its field type.
     *
     * @param array $column The column descriptor.
     * @param string $value The raw submitted value.
     * @return string|int|float|null The value in the shape instance_form_save
     *   expects; null for a number that does not parse, which
     *   {@see self::validate_cell()} reports.
     */
    private static function normalise_value(array $column, string $value) {
        if ($column['isnumber']) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                // Number fields treat '' as unset.
                return '';
            }
            $number = unformat_float($trimmed, true);
            return ($number === false || $number === null) ? null : $number;
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
        // Text: PARAM_TEXT, the type the field's own form element declares.
        return clean_param($value, PARAM_TEXT);
    }

    /**
     * Validate one normalised cell the way core's group form validates the field.
     *
     * The form checks a field in three layers, and this repeats them for the
     * one element a cell carries: the element's own format check (a number
     * must parse), the QuickForm rules the data controller adds in
     * instance_form_definition() (required, and a number's ceiling, which
     * {@see fields::number_ceiling()} reads from the column so it also holds
     * where core has no ceiling rule), then the controller's instance_form_validation()
     * (a number's minimum and maximum, a text's maximum length, a select's
     * required check, unique values). The handler-level
     * instance_form_validation() cannot be called instead: it validates every
     * editable field, and the text and number controllers read their element
     * from the data array unguarded, so a partial row would raise warnings.
     *
     * Two rules are this plugin's own, and apply to the seats field alone: a
     * seat count is never negative, whatever minimum an admin configures on the
     * field, and it is a whole number. The distribution reads seats as an
     * integer ({@see \local_groupdist\local\fields::get_group_values()}), so
     * 2.5 would be stored, shown rounded by the field's display and used
     * truncated. Every other number field keeps its own minimum and decimal
     * places. The settings modal applies the whole-number rule too
     * ({@see \local_groupdist\form\group_settings_form::validate_seats()}).
     *
     * @param array $column The column descriptor.
     * @param string|int|float|null $value The value from {@see self::normalise_value()}.
     * @param data_controller|null $data The group's data controller for this field.
     * @return string|null The error message, or null when the cell may be saved.
     */
    private static function validate_cell(array $column, $value, ?data_controller $data): ?string {
        if ($column['isnumber'] && $value === null) {
            return get_string('err_numeric', 'form');
        }
        if ($column['isnumber'] && $value !== '') {
            if ($column['isseats'] && $value < 0) {
                return get_string('minimumvalueerror', 'customfield_number', 0);
            }
            if ($column['isseats'] && floor($value) != $value) {
                return get_string('errorseatswhole', 'local_groupdist');
            }
            $ceiling = fields::number_ceiling();
            if ($value >= $ceiling) {
                return get_string('maximumvalueerror', 'customfield_number', $ceiling - 1);
            }
        }
        if (!$data) {
            // Not reached in practice: get_instances_data() returns a controller
            // for every field of every group, data or not.
            return null;
        }
        // An unchecked box submits nothing, so the required rule sees ''.
        $submitted = ($column['ischeckbox'] && !$value) ? '' : (string) $value;
        $required = $data->get_field()->get_configdata_property('required');
        if ($required && !(new \MoodleQuickForm_Rule_Required())->validate($submitted)) {
            return get_string('err_required', 'form');
        }
        $errors = $data->instance_form_validation([$data->get_form_element_name() => $value], []);
        return $errors ? (string) reset($errors) : null;
    }
}
