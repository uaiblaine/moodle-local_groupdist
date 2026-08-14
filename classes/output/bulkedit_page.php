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

namespace local_groupdist\output;

use core_group\customfield\group_handler;
use local_groupdist\local\fields;

/**
 * Bulk edit page: one table row per selected group, one column per group
 * custom field (this plugin's and any other), inline-editable where the
 * field type allows it.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulkedit_page implements \renderable, \templatable {
    /** @var string[] Field types editable inline; everything else edits via the modal. */
    public const INLINE_TYPES = ['number', 'text', 'select', 'checkbox'];

    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var array Selected group records, ordered by name. */
    protected array $groups;

    /**
     * Constructor.
     *
     * @param \stdClass $course The course record.
     * @param array $groups Selected group records (any order; sorted here).
     */
    public function __construct(\stdClass $course, array $groups) {
        $this->course = $course;
        usort($groups, function (\stdClass $a, \stdClass $b): int {
            return strcmp($a->name, $b->name) ?: ($a->id <=> $b->id);
        });
        $this->groups = $groups;
    }

    /**
     * Column metadata for every group custom field on the site.
     *
     * @return array List of column descriptors (key, shortname, label, type
     *   flags, select options).
     */
    public static function get_field_columns(): array {
        $columns = [];
        foreach (group_handler::create()->get_fields() as $field) {
            $type = $field->get('type');
            $options = [];
            if ($type === 'select') {
                $raw = (string) $field->get_configdata_property('options');
                foreach (preg_split('/\s*\n\s*/', trim($raw)) as $index => $label) {
                    // Select custom fields store the 1-based option index.
                    $options[] = ['value' => $index + 1, 'label' => format_string($label)];
                }
            }
            $columns[] = [
                'key' => 'cf_' . $field->get('shortname'),
                'shortname' => $field->get('shortname'),
                'label' => format_string($field->get('name')),
                'isseats' => $field->get('shortname') === fields::SHORTNAME_SEATS,
                'isnumber' => $type === 'number',
                'istext' => $type === 'text',
                'isselect' => $type === 'select',
                'ischeckbox' => $type === 'checkbox',
                'isreadonly' => !in_array($type, self::INLINE_TYPES, true),
                'options' => $options,
            ];
        }
        return $columns;
    }

    /**
     * Build one row's template context (also used to refresh a row after the
     * settings modal saves).
     *
     * @param \stdClass $group The group record.
     * @param array $columns Column descriptors from {@see get_field_columns()}.
     * @param array $fielddata Map fieldid => data controller for this group.
     * @param int $members Current member count.
     * @return array The row context.
     */
    public static function build_row(\stdClass $group, array $columns, array $fielddata, int $members): array {
        $bynames = [];
        foreach ($fielddata as $data) {
            $bynames[$data->get_field()->get('shortname')] = $data;
        }

        $seats = null;
        $cells = [];
        foreach ($columns as $column) {
            $data = $bynames[$column['shortname']] ?? null;
            $rawvalue = '';
            $displayvalue = '';
            $checked = false;
            $options = $column['options'];
            if ($data) {
                $value = $data->get_value();
                if ($column['isnumber']) {
                    $rawvalue = ($value === null || $value === '') ? '' : (string) (float) $value;
                    if ($rawvalue !== '' && (float) $rawvalue == (int) (float) $rawvalue) {
                        $rawvalue = (string) (int) (float) $rawvalue;
                    }
                } else if ($column['isselect']) {
                    $selected = (int) $value;
                    $options = array_map(function (array $option) use ($selected): array {
                        return $option + ['selected' => $option['value'] === $selected];
                    }, $options);
                } else if ($column['ischeckbox']) {
                    $checked = !empty($value);
                } else if ($column['isreadonly']) {
                    $displayvalue = (string) $data->export_value();
                } else {
                    $rawvalue = (string) $value;
                }
            }
            if ($column['isseats']) {
                $seats = ($rawvalue === '') ? null : (int) $rawvalue;
            }
            $cells[] = $column + [
                'value' => $rawvalue,
                'displayvalue' => $displayvalue,
                'checked' => $checked,
                'options' => $options,
                'empty' => $column['isseats'] && $rawvalue === '',
            ];
        }

        $over = ($seats !== null && $members > $seats) ? $members - $seats : 0;
        $pictureurl = get_group_picture_url($group, $group->courseid, false);
        return [
            'groupid' => (int) $group->id,
            'name' => format_string($group->name),
            'idnumber' => (string) $group->idnumber,
            'pictureurl' => $pictureurl ? $pictureurl->out(false) : '',
            'initial' => mb_strtoupper(mb_substr(format_string($group->name), 0, 1)),
            'members' => $members,
            'over' => $over,
            'isover' => $over > 0,
            'cells' => $cells,
        ];
    }

    /**
     * Export the template context.
     *
     * @param \renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(\renderer_base $output): array {
        $groupids = array_map(function (\stdClass $group): int {
            return (int) $group->id;
        }, $this->groups);

        $columns = self::get_field_columns();
        $alldata = group_handler::create()->get_instances_data($groupids, true);
        $counts = fields::get_member_counts($groupids);

        $rows = [];
        foreach ($this->groups as $group) {
            $rows[] = self::build_row(
                $group,
                $columns,
                $alldata[(int) $group->id] ?? [],
                $counts[(int) $group->id] ?? 0
            );
        }

        // Column visibility is a per-user preference (csv of column keys).
        $hidden = array_filter(explode(',', (string) get_user_preferences('local_groupdist_bulkedit_hiddencols', '')));
        $menucolumns = [];
        $togglable = array_merge(
            [
                ['key' => 'id', 'label' => get_string('idnumbercolumn', 'local_groupdist'), 'isseats' => false],
                ['key' => 'members', 'label' => get_string('memberscolumn', 'local_groupdist'), 'isseats' => false],
            ],
            $columns
        );
        foreach ($togglable as $column) {
            if (!empty($column['isseats'])) {
                // Seats stays visible: it is the one field the distribution requires.
                continue;
            }
            $menucolumns[] = [
                'key' => $column['key'],
                'label' => $column['label'],
                'visible' => !in_array($column['key'], $hidden, true),
            ];
        }

        return [
            'courseid' => (int) $this->course->id,
            'total' => count($rows),
            'rows' => $rows,
            'columns' => $columns,
            'menucolumns' => $menucolumns,
            'hiddencols' => implode(',', $hidden),
            'seatslabel' => fields::get_seats_label(),
            'seatsshortname' => fields::SHORTNAME_SEATS,
            'sesskey' => sesskey(),
        ];
    }
}
