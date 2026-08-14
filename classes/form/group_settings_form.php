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

namespace local_groupdist\form;

use core_form\dynamic_form;
use core_group\customfield\group_handler;
use local_groupdist\local\fields;
use local_groupdist\output\bulkedit_page;

/**
 * Group settings modal on the bulk edit page: a dynamic-form wrap of the
 * essentials of core's group edit form (name, ID number, description,
 * messaging) plus every group custom field. The group picture and the other
 * long-tail settings stay on core's own page.
 *
 * The submission returns the refreshed bulk-edit row context so the table
 * updates in place.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_settings_form extends dynamic_form {
    /** @var \stdClass|null Cached group record. */
    protected ?\stdClass $group = null;

    /**
     * The group being edited.
     *
     * @return \stdClass The group record.
     */
    protected function get_group(): \stdClass {
        if ($this->group === null) {
            $groupid = (int) $this->optional_param('groupid', 0, PARAM_INT);
            $this->group = groups_get_group($groupid, '*', MUST_EXIST);
        }
        return $this->group;
    }

    /**
     * Editor options, mirroring group/group.php.
     *
     * @param \core\context\course $context The course context.
     * @return array The options.
     */
    protected function get_editor_options(\core\context\course $context): array {
        $course = get_course($this->get_group()->courseid);
        return [
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'maxbytes' => $course->maxbytes,
            'trust' => false,
            'context' => $context,
            'noclean' => true,
            'subdirs' => file_area_contains_subdirs($context, 'group', 'description', $this->get_group()->id),
        ];
    }

    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        global $USER;
        $mform = $this->_form;
        $context = $this->get_context_for_dynamic_submission();

        $mform->addElement('hidden', 'groupid');
        $mform->setType('groupid', PARAM_INT);

        $mform->addElement('text', 'name', get_string('groupname', 'group'), 'maxlength="254"');
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'idnumber', get_string('idnumbergroup'), 'maxlength="100"');
        $mform->setType('idnumber', PARAM_RAW);
        if (!has_capability('moodle/course:changeidnumber', $context)) {
            $mform->hardFreeze('idnumber');
        }

        $mform->addElement(
            'editor',
            'description_editor',
            get_string('groupdescription', 'group'),
            null,
            $this->get_editor_options($context)
        );
        $mform->setType('description_editor', PARAM_RAW);

        if (\core_message\api::can_create_group_conversation($USER->id, $context)) {
            $mform->addElement('selectyesno', 'enablemessaging', get_string('enablemessaging', 'group'));
        }

        group_handler::create()->instance_form_definition($mform, (int) $this->get_group()->id);
    }

    /**
     * Context: the group's course.
     *
     * @return \core\context The course context.
     */
    protected function get_context_for_dynamic_submission(): \core\context {
        return \core\context\course::instance($this->get_group()->courseid);
    }

    /**
     * Access control: same gate as the bulk edit page.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('moodle/course:managegroups', $this->get_context_for_dynamic_submission());
    }

    /**
     * Load current data.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $context = $this->get_context_for_dynamic_submission();
        $group = clone $this->get_group();
        $group->groupid = $group->id;
        $group = file_prepare_standard_editor(
            $group,
            'description',
            $this->get_editor_options($context),
            $context,
            'group',
            'description',
            $group->id
        );
        group_handler::create()->instance_form_before_set_data($group);
        $this->set_data($group);
    }

    /**
     * Duplicate-name guard, like core's group form.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $group = $this->get_group();
        $name = trim($data['name'] ?? '');
        $existing = groups_get_group_by_name($group->courseid, $name);
        if ($existing && (int) $existing !== (int) $group->id) {
            $errors['name'] = get_string('groupnameexists', 'group', $name);
        }
        return $errors;
    }

    /**
     * Save and return the refreshed bulk-edit row context.
     *
     * @return array The row context for the table update.
     */
    public function process_dynamic_submission(): array {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $data = $this->get_data();
        $group = $this->get_group();
        $context = $this->get_context_for_dynamic_submission();

        $data->id = $group->id;
        $data->courseid = $group->courseid;
        $data = file_postupdate_standard_editor(
            $data,
            'description',
            $this->get_editor_options($context),
            $context,
            'group',
            'description',
            $group->id
        );
        groups_update_group($data);
        group_handler::create()->instance_form_save($data);

        // Refreshed row for the client-side table update.
        $fresh = groups_get_group($group->id, '*', MUST_EXIST);
        $columns = bulkedit_page::get_field_columns();
        $fielddata = group_handler::create()->get_instances_data([(int) $group->id], true);
        $counts = fields::get_member_counts([(int) $group->id]);
        return bulkedit_page::build_row(
            $fresh,
            $columns,
            $fielddata[(int) $group->id] ?? [],
            $counts[(int) $group->id] ?? 0
        );
    }

    /**
     * URL of the page hosting this form.
     *
     * @return \moodle_url The bulk edit page.
     */
    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return new \moodle_url('/local/groupdist/bulkedit.php', ['id' => $this->get_group()->courseid]);
    }
}
