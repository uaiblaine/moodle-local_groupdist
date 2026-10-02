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
     * Format a stored name for output, in the plain spelling
     * ({@see \local_groupdist\local\plaintext::format()}).
     *
     * Every consumer of this class's context arrays escapes for itself: the
     * table renders through Mustache double stashes, and bulkedit.js writes
     * the same values with textContent after the settings modal saves. With
     * format_string()'s default escaping a group called "Ana & Bruno" would
     * read "Ana &amp; Bruno" on screen in both.
     *
     * @param string $text The stored name.
     * @param \core\context $context The context to format in.
     * @return string The formatted name, not HTML-escaped.
     */
    protected static function plain(string $text, \core\context $context): string {
        return \local_groupdist\local\plaintext::format($text, $context);
    }

    /**
     * Column metadata for every group custom field on the site.
     *
     * @return array List of column descriptors (key, shortname, label, type
     *   flags, the number input's hasmin/min/step, select options).
     */
    public static function get_field_columns(): array {
        /* Group custom fields are defined site-wide: group_handler's own
           get_configuration_context() is the system context. */
        $fieldcontext = \core\context\system::instance();
        $columns = [];
        foreach (group_handler::create()->get_fields() as $field) {
            $type = $field->get('type');
            $options = [];
            if ($type === 'select') {
                $raw = (string) $field->get_configdata_property('options');
                foreach (preg_split('/\s*\n\s*/', trim($raw)) as $index => $label) {
                    // Select custom fields store the 1-based option index.
                    $options[] = ['value' => $index + 1, 'label' => self::plain($label, $fieldcontext)];
                }
            }
            $isseats = $field->get('shortname') === fields::SHORTNAME_SEATS;
            /* A number field whose value a provider computes (customfield_number's
               "field type" setting) is not typed by hand: core's group form shows
               it read-only, and so does the table. */
            $computed = $field instanceof \customfield_number\field_controller && !$field->is_editable();
            $isnumber = $type === 'number' && !$computed;
            /* The input's min attribute states the floor the inline save enforces:
               the field's own minimum, and for seats never below 0
               ({@see \local_groupdist\external\save_group_fields::validate_cell()}). */
            $minimum = $isnumber ? (string) ($field->get_configdata_property('minimumvalue') ?? '') : '';
            if ($isseats && $isnumber && ($minimum === '' || (float) $minimum < 0)) {
                $minimum = '0';
            }
            $columns[] = [
                'key' => 'cf_' . $field->get('shortname'),
                'shortname' => $field->get('shortname'),
                'label' => self::plain($field->get('name'), $fieldcontext),
                'isseats' => $isseats,
                'isnumber' => $isnumber,
                'istext' => $type === 'text',
                'isselect' => $type === 'select',
                'ischeckbox' => $type === 'checkbox',
                'isreadonly' => $computed || !in_array($type, self::INLINE_TYPES, true),
                // A boolean beside the value: Mustache's PHP renderer skips a section whose value is the string "0".
                'hasmin' => $minimum !== '',
                'min' => $minimum,
                // Seats take whole numbers only; other number fields keep their configured decimal places.
                'step' => $isseats ? '1' : 'any',
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
        $name = self::plain($group->name, \core\context\course::instance($group->courseid));
        return [
            'groupid' => (int) $group->id,
            'name' => $name,
            'idnumber' => (string) $group->idnumber,
            'pictureurl' => $pictureurl ? $pictureurl->out(false) : '',
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
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
                // Seats stays visible: it is the capacity field the distribution reads.
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
