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
 * Group settings modal on the bulk edit page: a dynamic-form wrap of core's
 * whole group edit form — name, ID number, description, enrolment key,
 * membership visibility, participation, messaging, picture — plus every group
 * custom field, so the modal never sends anyone to group/group.php to finish
 * a job it started.
 *
 * The submission returns the refreshed bulk-edit row context so the table
 * updates in place.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_settings_form extends dynamic_form {
    /** @var int Length of the {groups}.enrolmentkey column. */
    public const ENROLMENTKEY_MAXLENGTH = 50;

    /** @var string[] Picture extensions process_new_icon() can decode. */
    public const PICTURE_TYPES = ['.gif', '.jpe', '.jpeg', '.jpg', '.png'];

    /** @var string[] Image mime types process_new_icon() can decode. */
    public const PICTURE_MIMETYPES = ['image/gif', 'image/jpeg', 'image/png'];

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
     * Form definition, element for element as in group/group_form.php.
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
        $mform->addHelpButton('idnumber', 'idnumbergroup');
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

        $keyattributes = 'maxlength="' . self::ENROLMENTKEY_MAXLENGTH . '"';
        $mform->addElement('passwordunmask', 'enrolmentkey', get_string('enrolmentkey', 'group'), $keyattributes);
        $mform->addHelpButton('enrolmentkey', 'enrolmentkey', 'group');
        $mform->setType('enrolmentkey', PARAM_RAW);

        $visibilityoptions = [
            GROUPS_VISIBILITY_ALL => get_string('visibilityall', 'group'),
            GROUPS_VISIBILITY_MEMBERS => get_string('visibilitymembers', 'group'),
            GROUPS_VISIBILITY_OWN => get_string('visibilityown', 'group'),
            GROUPS_VISIBILITY_NONE => get_string('visibilitynone', 'group'),
        ];
        $mform->addElement('select', 'visibility', get_string('visibility', 'group'), $visibilityoptions);
        $mform->addHelpButton('visibility', 'visibility', 'group');
        $mform->setType('visibility', PARAM_INT);

        $hiddenvisibilities = [GROUPS_VISIBILITY_OWN, GROUPS_VISIBILITY_NONE];
        $mform->addElement('advcheckbox', 'participation', '', get_string('participation', 'group'));
        $mform->addHelpButton('participation', 'participation', 'group');
        $mform->setType('participation', PARAM_BOOL);
        $mform->setDefault('participation', 1);
        $mform->hideIf('participation', 'visibility', 'in', $hiddenvisibilities);

        if (\core_message\api::can_create_group_conversation($USER->id, $context)) {
            $mform->addElement('selectyesno', 'enablemessaging', get_string('enablemessaging', 'group'));
            $mform->addHelpButton('enablemessaging', 'enablemessaging', 'group');
            $mform->hideIf('enablemessaging', 'visibility', 'in', $hiddenvisibilities);
        }

        $mform->addElement('static', 'currentpicture', get_string('currentpicture'));

        $mform->addElement('checkbox', 'deletepicture', get_string('delete'));
        $mform->setDefault('deletepicture', 0);

        /* Core passes no options here, which accepts any file and then lets
           process_new_icon() fail inside groups_update_group_icon() — and that
           failure path DELETES the group's existing picture. This list mirrors
           process_new_icon()'s own switch (gdlib.php: GIF, JPEG, PNG) so the
           loss becomes a form error instead. Do NOT simplify it to a file-type
           group: 'web_image' carries svg, svgz and webp and 'optimised_image'
           carries webp, none of which GD writes here, so the picker would
           advertise formats that destroy the picture on save. The extension is
           only half the check — validation() reads the file itself. */
        $mform->addElement('filepicker', 'imagefile', get_string('newpicture', 'group'), null, [
            'accepted_types' => self::PICTURE_TYPES,
        ]);
        $mform->addHelpButton('imagefile', 'newpicture', 'group');

        group_handler::create()->instance_form_definition($mform, (int) $this->get_group()->id);
    }

    /**
     * Late definition: the current picture, the stored messaging state, and
     * core's rule that a populated group's visibility and participation are
     * locked.
     *
     * @return void
     */
    public function definition_after_data(): void {
        global $DB, $USER;
        $mform = $this->_form;
        $group = $this->get_group();
        $groupid = (int) $group->id;
        $context = $this->get_context_for_dynamic_submission();

        if (\core_message\api::can_create_group_conversation($USER->id, $context)) {
            if (\core_message\api::is_conversation_area_enabled('core_group', 'groups', $groupid, $context->id)) {
                $mform->getElement('enablemessaging')->setSelected(1);
            }
        }

        $picture = $this->render_current_picture($group);
        if ($picture === '') {
            $picture = get_string('none');
            $mform->removeElement('deletepicture');
        }
        $mform->getElement('currentpicture')->setValue($picture);

        // Core forbids changing either one once the group has members.
        if ($DB->record_exists('groups_members', ['groupid' => $groupid])) {
            $mform->getElement('visibility')->freeze();
            $mform->getElement('participation')->freeze();
        }

        group_handler::create()->instance_form_definition_after_data($mform, $groupid);
    }

    /**
     * The current group picture, rendered without the link core's
     * print_group_picture() still adds for accessallgroups holders even when
     * asked not to: following it would leave the modal and lose the edits.
     *
     * @param \stdClass $group The group record.
     * @return string The picture HTML, or '' when the group has none.
     */
    protected function render_current_picture(\stdClass $group): string {
        global $OUTPUT;
        $url = get_group_picture_url($group, $group->courseid, true);
        if ($url === null) {
            return '';
        }
        $context = $this->get_context_for_dynamic_submission();
        $name = format_string($group->name, true, ['context' => $context, 'escape' => false]);
        return $OUTPUT->render_from_template('local_groupdist/group_picture', [
            'url' => $url->out(false),
            'name' => $name,
        ]);
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
     * Core's group form validation: unique name, unique ID number, and the
     * enrolment key rules.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $group = $this->get_group();
        $courseid = (int) $group->courseid;

        $name = trim($data['name'] ?? '');
        $existing = groups_get_group_by_name($courseid, $name);
        if ($existing && (int) $existing !== (int) $group->id) {
            $errors['name'] = get_string('groupnameexists', 'group', $name);
        }

        $idnumber = trim($data['idnumber'] ?? '');
        if ($idnumber !== '' && $idnumber !== $group->idnumber && groups_get_group_by_idnumber($courseid, $idnumber)) {
            $errors['idnumber'] = get_string('idnumbertaken');
        }

        $key = (string) ($data['enrolmentkey'] ?? '');
        if ($key !== '') {
            $errors += $this->validate_enrolment_key($key, $group);
        }

        $errors += $this->validate_picture((int) ($data['imagefile'] ?? 0));

        $handlererrors = group_handler::create()->instance_form_validation($data, $files);
        return array_merge($errors, $handlererrors);
    }

    /**
     * Core's enrolment key rules: the site key policy when the key changes,
     * and uniqueness across the course's groups. The length check is this
     * plugin's own — the modal is a web service endpoint, so the maxlength
     * attribute is trivially bypassed, and an over-long key would otherwise
     * reach the 50-character column as a raw DML failure.
     *
     * @param string $key The submitted key, known to be non-empty.
     * @param \stdClass $group The group being edited.
     * @return array Errors keyed by element name, empty when the key is fine.
     */
    protected function validate_enrolment_key(string $key, \stdClass $group): array {
        global $CFG, $DB;

        if (\core_text::strlen($key) > self::ENROLMENTKEY_MAXLENGTH) {
            return ['enrolmentkey' => get_string('maximumchars', '', self::ENROLMENTKEY_MAXLENGTH)];
        }

        $errmsg = '';
        $changed = (string) $group->enrolmentkey !== $key;
        if ($changed && !empty($CFG->groupenrolmentkeypolicy) && !check_password_policy($key, $errmsg)) {
            return ['enrolmentkey' => $errmsg];
        }

        $sql = "SELECT id FROM {groups} WHERE id <> :groupid AND courseid = :courseid AND enrolmentkey = :key";
        $params = ['groupid' => (int) $group->id, 'courseid' => (int) $group->courseid, 'key' => $key];
        if ($DB->record_exists_sql($sql, $params)) {
            return ['enrolmentkey' => get_string('enrolmentkeyalreadyinuse', 'group')];
        }

        return [];
    }

    /**
     * Reject an upload process_new_icon() cannot decode, by reading the file
     * rather than trusting its name. The filepicker's own allowlist is
     * extension-only, and a file GD cannot read reaches
     * groups_update_group_icon(), whose failure branch deletes the group's
     * existing picture.
     *
     * @param int $draftid The submitted draft item id, 0 when nothing was picked.
     * @return array Errors keyed by element name, empty when the file is fine.
     */
    protected function validate_picture(int $draftid): array {
        global $USER;

        if ($draftid <= 0) {
            return [];
        }
        $usercontext = \core\context\user::instance($USER->id);
        $files = get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $draftid, 'id DESC', false);
        $file = reset($files);
        if (!$file) {
            return [];
        }
        $info = $file->get_imageinfo();
        if (!$info || !in_array($info['mimetype'] ?? '', self::PICTURE_MIMETYPES, true)) {
            return ['imagefile' => get_string('invalidfiletype', 'error', $file->get_filename())];
        }

        return [];
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
        if (!has_capability('moodle/course:changeidnumber', $context)) {
            // Belt and braces, exactly as group/group.php does it.
            unset($data->idnumber);
        }

        /* Two arguments on purpose. Passing $editform is what makes
           groups_update_group() write the picture through
           groups_update_group_icon(); passing $editoroptions as a third would
           re-run the editor post-update already done above. The call also
           saves the group custom fields, so this method must not. */
        groups_update_group($data, $this);

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
