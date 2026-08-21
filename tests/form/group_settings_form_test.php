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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Group settings modal tests: parity with core's group edit form.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\form\group_settings_form::class)]
final class group_settings_form_test extends \advanced_testcase {
    /** @var array Every element core's group_form.php offers for an existing group. */
    private const CORE_ELEMENTS = [
        'name',
        'idnumber',
        'description_editor',
        'enrolmentkey',
        'visibility',
        'participation',
        'currentpicture',
        'deletepicture',
        'imagefile',
    ];

    /**
     * Build the modal form for one group, as the web service does.
     *
     * @param int $groupid The group id.
     * @param array $submitted Extra submitted values; when non-empty the form
     *   is built as a submission rather than a fresh render.
     * @return group_settings_form The form.
     */
    private function make_form(int $groupid, array $submitted = []): group_settings_form {
        $data = ['groupid' => $groupid] + $submitted;
        if ($submitted) {
            // The confirm_sesskey() call reads the superglobal, never the ajax payload.
            $_POST['sesskey'] = sesskey();
            $data['sesskey'] = sesskey();
            $data['_qf__local_groupdist_form_group_settings_form'] = 1;
        }
        return new group_settings_form(null, null, 'post', '', [], true, $data, true);
    }

    /**
     * The form's inner MoodleQuickForm.
     *
     * @param group_settings_form $form The form.
     * @return \MoodleQuickForm The quickform instance.
     */
    private function mform(group_settings_form $form): \MoodleQuickForm {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        return $property->getValue($form);
    }

    /**
     * A course with one group and an admin session.
     *
     * @return array [course, group].
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        return [$course, $group];
    }

    /**
     * Every element of core's group edit form is offered by the modal.
     *
     * @return void
     */
    public function test_definition_covers_every_core_element(): void {
        [, $group] = $this->setup_course();
        $mform = $this->mform($this->make_form((int) $group->id));

        foreach (self::CORE_ELEMENTS as $name) {
            $this->assertTrue($mform->elementExists($name), "Element '$name' is missing from the modal.");
        }
    }

    /**
     * The visibility menu offers core's four values, not a subset.
     *
     * @return void
     */
    public function test_visibility_offers_every_core_value(): void {
        [, $group] = $this->setup_course();
        $mform = $this->mform($this->make_form((int) $group->id));

        $options = $mform->getElement('visibility')->_options;
        $values = array_map(static function ($option) {
            return (int) $option['attr']['value'];
        }, $options);
        $expected = [
            GROUPS_VISIBILITY_ALL,
            GROUPS_VISIBILITY_MEMBERS,
            GROUPS_VISIBILITY_OWN,
            GROUPS_VISIBILITY_NONE,
        ];
        $this->assertSame($expected, $values);
    }

    /**
     * An empty group's visibility and participation stay editable.
     *
     * @return void
     */
    public function test_visibility_editable_while_the_group_is_empty(): void {
        [, $group] = $this->setup_course();
        $form = $this->make_form((int) $group->id);
        $form->definition_after_data();
        $mform = $this->mform($form);

        $this->assertFalse($mform->getElement('visibility')->isFrozen());
        $this->assertFalse($mform->getElement('participation')->isFrozen());
    }

    /**
     * Core forbids changing either one once the group has members, and the
     * modal must honour that. The empty-group case above is the control: if
     * the freeze were unconditional both tests could not pass together.
     *
     * @return void
     */
    public function test_visibility_frozen_once_the_group_has_members(): void {
        [$course, $group] = $this->setup_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        groups_add_member($group->id, $user->id);

        $form = $this->make_form((int) $group->id);
        $form->definition_after_data();
        $mform = $this->mform($form);

        $this->assertTrue($mform->getElement('visibility')->isFrozen());
        $this->assertTrue($mform->getElement('participation')->isFrozen());
    }

    /**
     * Without a picture there is nothing to delete, so core drops the
     * checkbox and says so in the static row.
     *
     * @return void
     */
    public function test_delete_picture_row_disappears_without_a_picture(): void {
        [, $group] = $this->setup_course();
        $form = $this->make_form((int) $group->id);
        $form->definition_after_data();
        $mform = $this->mform($form);

        $this->assertFalse($mform->elementExists('deletepicture'));
        $this->assertStringContainsString(get_string('none'), $mform->getElement('currentpicture')->toHtml());
    }

    /**
     * A picture is shown, and shown without a link — following one from
     * inside the modal would discard the unsaved form.
     *
     * @return void
     */
    public function test_current_picture_is_rendered_unlinked(): void {
        global $DB;
        [$course, $group] = $this->setup_course();
        $this->make_group_picture($course, $group);

        $form = $this->make_form((int) $group->id);
        $form->definition_after_data();
        $mform = $this->mform($form);

        $picture = $mform->getElement('currentpicture')->toHtml();
        $this->assertStringContainsString('local-groupdist-curpic', $picture);
        $this->assertStringNotContainsString('<a ', $picture);
        $this->assertTrue($mform->elementExists('deletepicture'));
        $this->assertGreaterThan(0, (int) $DB->get_field('groups', 'picture', ['id' => $group->id]));
    }

    /**
     * Give a group a picture, the way groups_update_group_icon() does.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $group The group.
     * @return void
     */
    private function make_group_picture(\stdClass $course, \stdClass $group): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gdlib.php');
        $context = \core\context\course::instance($course->id);
        $source = $CFG->dirroot . '/lib/tests/fixtures/gd-logo.png';
        $rev = process_new_icon($context, 'group', 'icon', $group->id, $source);
        $DB->set_field('groups', 'picture', $rev, ['id' => $group->id]);
        $group->picture = $rev;
    }

    /**
     * An enrolment key already used by another group in the course is
     * rejected, as it is on core's own form.
     *
     * @return void
     */
    public function test_duplicate_enrolment_key_is_rejected(): void {
        global $CFG;
        [$course, $group] = $this->setup_course();
        // Isolate uniqueness from the key policy, which is on by default.
        $CFG->groupenrolmentkeypolicy = 0;
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'enrolmentkey' => 'sharedkey']);

        $form = $this->make_form((int) $group->id);
        $errors = $form->validation(['name' => $group->name, 'enrolmentkey' => 'sharedkey'], []);

        $this->assertArrayHasKey('enrolmentkey', $errors);
        $this->assertSame(get_string('enrolmentkeyalreadyinuse', 'group'), $errors['enrolmentkey']);
    }

    /**
     * The group's own key is not a duplicate of itself.
     *
     * @return void
     */
    public function test_unchanged_enrolment_key_is_accepted(): void {
        global $DB;
        [, $group] = $this->setup_course();
        $DB->set_field('groups', 'enrolmentkey', 'ownkey', ['id' => $group->id]);

        $form = $this->make_form((int) $group->id);
        $errors = $form->validation(['name' => $group->name, 'enrolmentkey' => 'ownkey'], []);

        $this->assertArrayNotHasKey('enrolmentkey', $errors);
    }

    /**
     * The column is 50 characters wide; a longer key must come back as a
     * field error rather than a DML failure.
     *
     * @return void
     */
    public function test_overlong_enrolment_key_is_rejected(): void {
        [, $group] = $this->setup_course();
        // Policy-compliant, so only the length guard can be what rejects it.
        $long = 'Str0ng!' . str_repeat('a', group_settings_form::ENROLMENTKEY_MAXLENGTH - 6);
        $this->assertSame(group_settings_form::ENROLMENTKEY_MAXLENGTH + 1, \core_text::strlen($long));

        $form = $this->make_form((int) $group->id);
        $errors = $form->validation(['name' => $group->name, 'enrolmentkey' => $long], []);

        $expected = get_string('maximumchars', '', group_settings_form::ENROLMENTKEY_MAXLENGTH);
        $this->assertSame($expected, $errors['enrolmentkey'] ?? '');
    }

    /**
     * With the site policy on, a weak new key is rejected.
     *
     * @return void
     */
    public function test_enrolment_key_policy_is_enforced(): void {
        global $CFG;
        [, $group] = $this->setup_course();
        $CFG->groupenrolmentkeypolicy = 1;
        $CFG->minpasswordlength = 8;
        $CFG->minpassworddigits = 1;
        $CFG->minpasswordlower = 1;
        $CFG->minpasswordupper = 1;
        $CFG->minpasswordnonalphanum = 1;

        $form = $this->make_form((int) $group->id);
        $errors = $form->validation(['name' => $group->name, 'enrolmentkey' => 'abc'], []);
        $this->assertArrayHasKey('enrolmentkey', $errors);

        $strong = $form->validation(['name' => $group->name, 'enrolmentkey' => 'Str0ng!key'], []);
        $this->assertArrayNotHasKey('enrolmentkey', $strong);
    }

    /**
     * An ID number already used in the course is rejected.
     *
     * @return void
     */
    public function test_duplicate_idnumber_is_rejected(): void {
        [$course, $group] = $this->setup_course();
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'idnumber' => 'TAKEN']);

        $form = $this->make_form((int) $group->id);
        $errors = $form->validation(['name' => $group->name, 'idnumber' => 'TAKEN'], []);

        $this->assertArrayHasKey('idnumber', $errors);
    }

    /**
     * The new fields really reach the database through the modal's own save
     * path, and the refreshed row context comes back with them applied.
     *
     * @return void
     */
    public function test_submission_saves_the_new_fields(): void {
        global $DB;
        [, $group] = $this->setup_course();

        $form = $this->make_form((int) $group->id, [
            'name' => 'Renamed group',
            'idnumber' => 'NEWID',
            'description_editor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
            'enrolmentkey' => 'Str0ng!key',
            'visibility' => GROUPS_VISIBILITY_MEMBERS,
            'participation' => 0,
        ]);
        $valid = $form->is_validated();
        $this->assertTrue($valid, 'Did not validate: ' . json_encode($this->mform($form)->_errors));
        $row = $form->process_dynamic_submission();

        $saved = $DB->get_record('groups', ['id' => $group->id], '*', MUST_EXIST);
        $this->assertSame('Renamed group', $saved->name);
        $this->assertSame('NEWID', $saved->idnumber);
        $this->assertSame('Str0ng!key', $saved->enrolmentkey);
        $this->assertEquals(GROUPS_VISIBILITY_MEMBERS, $saved->visibility);
        $this->assertEquals(0, $saved->participation);
        $this->assertSame('Renamed group', $row['name']);
    }

    /**
     * Opening the modal and saving it untouched must change nothing. Every
     * element added for parity is a way to silently destroy a stored value:
     * a field that renders empty where the record holds a value wipes it on
     * the next save, and the modal is the fast path teachers will use.
     *
     * @return void
     */
    public function test_an_untouched_save_changes_nothing(): void {
        global $DB;
        [$course, $group] = $this->setup_course();
        $DB->update_record('groups', (object) [
            'id' => $group->id,
            'idnumber' => 'KEEPME',
            'enrolmentkey' => 'K33p!thiskey',
            'visibility' => GROUPS_VISIBILITY_MEMBERS,
            'participation' => 0,
        ]);
        $this->make_group_picture($course, $group);
        $before = $DB->get_record('groups', ['id' => $group->id], '*', MUST_EXIST);

        $form = $this->make_form((int) $group->id);
        $form->set_data_for_dynamic_submission();
        // What the rendered form would post, including the late definition.
        $form->definition_after_data();
        $submitted = $this->mform($form)->exportValues();

        $resubmit = $this->make_form((int) $group->id, $submitted);
        $this->assertTrue($resubmit->is_validated(), 'Did not validate: ' . json_encode($this->mform($resubmit)->_errors));
        $resubmit->process_dynamic_submission();

        $after = $DB->get_record('groups', ['id' => $group->id], '*', MUST_EXIST);
        unset($before->timemodified, $after->timemodified);
        $this->assertEquals($before, $after, 'An untouched save changed the group record.');
    }

    /**
     * The picture really saves through the modal. This is the path with no
     * core precedent on a group: the filepicker posts a draft item id rather
     * than a file, and groups_update_group_icon() reads it back out of the
     * draft area through moodleform::save_temp_file().
     *
     * @return void
     */
    public function test_submission_saves_a_new_picture(): void {
        global $CFG, $DB, $USER;
        [, $group] = $this->setup_course();
        $this->assertEquals(0, (int) $DB->get_field('groups', 'picture', ['id' => $group->id]));

        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname([
            'contextid' => \core\context\user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'logo.png',
        ], $CFG->dirroot . '/lib/tests/fixtures/gd-logo.png');

        $form = $this->make_form((int) $group->id, [
            'name' => $group->name,
            'description_editor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
            'imagefile' => $draftid,
        ]);
        $this->assertTrue($form->is_validated(), 'Did not validate: ' . json_encode($this->mform($form)->_errors));
        $row = $form->process_dynamic_submission();

        $this->assertGreaterThan(0, (int) $DB->get_field('groups', 'picture', ['id' => $group->id]));
        $this->assertNotSame('', $row['pictureurl'], 'The refreshed row must carry the new picture.');
    }

    /**
     * A file process_new_icon() cannot decode is a field error, not the
     * silent deletion of the existing picture that reaching
     * groups_update_group_icon() would cause.
     *
     * Every case here is one the obvious allowlist would have let through:
     * svg/svgz/webp are all in core's 'web_image' group (webp is in
     * 'optimised_image' too) and GD writes none of them, and the renamed text
     * file is what proves the check reads the file rather than its name. A
     * plain .txt would pass this test against any allowlist at all.
     *
     * @param string $filename The uploaded name.
     * @param string $content The uploaded bytes.
     * @return void
     */
    #[DataProvider('undecodable_picture_provider')]
    public function test_an_undecodable_picture_is_rejected(string $filename, string $content): void {
        global $DB, $USER;
        [$course, $group] = $this->setup_course();
        $this->make_group_picture($course, $group);
        $before = (int) $DB->get_field('groups', 'picture', ['id' => $group->id]);
        $this->assertGreaterThan(0, $before);

        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \core\context\user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);

        $form = $this->make_form((int) $group->id, [
            'name' => $group->name,
            'description_editor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
            'imagefile' => $draftid,
        ]);

        $this->assertFalse($form->is_validated(), "$filename was accepted.");
        $this->assertArrayHasKey('imagefile', $this->mform($form)->_errors);
        $this->assertSame($before, (int) $DB->get_field('groups', 'picture', ['id' => $group->id]));
    }

    /**
     * Uploads that must never reach process_new_icon().
     *
     * @return array Cases of [filename, content].
     */
    public static function undecodable_picture_provider(): array {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8"/></svg>';
        return [
            'svg' => ['pic.svg', $svg],
            'svgz' => ['pic.svgz', (string) gzencode($svg)],
            'webp' => ['pic.webp', 'RIFF....WEBPVP8 '],
            'text renamed to png' => ['pic.png', 'not an image at all'],
            'plain text' => ['notes.txt', 'not an image'],
        ];
    }

    /**
     * Ticking "delete" removes the picture.
     *
     * @return void
     */
    public function test_submission_deletes_the_picture(): void {
        global $DB;
        [$course, $group] = $this->setup_course();
        $this->make_group_picture($course, $group);
        $this->assertGreaterThan(0, (int) $DB->get_field('groups', 'picture', ['id' => $group->id]));

        $form = $this->make_form((int) $group->id, [
            'name' => $group->name,
            'description_editor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
            'deletepicture' => 1,
        ]);
        $this->assertTrue($form->is_validated(), 'Did not validate: ' . json_encode($this->mform($form)->_errors));
        $row = $form->process_dynamic_submission();

        $this->assertEquals(0, (int) $DB->get_field('groups', 'picture', ['id' => $group->id]));
        $this->assertSame('', $row['pictureurl']);
    }

    /**
     * Group custom fields still save now that the modal's own
     * instance_form_save() call is gone and groups_update_group() makes the
     * only one. Once-ness itself is NOT asserted here and cannot be by a value
     * assertion: instance_form_save() reloads its data controllers from
     * {customfield_data} on every call, so a second call is idempotent.
     *
     * @return void
     */
    public function test_custom_fields_still_save_through_the_modal(): void {
        [, $group] = $this->setup_course();
        \local_groupdist\local\fields::reset_field_cache();
        \local_groupdist\local\fields::ensure_fields_exist();
        \local_groupdist\local\fields::reset_field_cache();

        $seatsfield = \local_groupdist\local\fields::get_seats_field();
        $locationfield = \local_groupdist\local\fields::get_location_field();
        $form = $this->make_form((int) $group->id, [
            'name' => $group->name,
            'description_editor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
            'customfield_' . $seatsfield->get('shortname') => 17,
            // The customfield_number element pairs the input with a hidden ceiling element.
            'customfield_' . $seatsfield->get('shortname') . '_maximum' => SQL_INT_MAX + 1,
            'customfield_' . $locationfield->get('shortname') => 'Lab 1',
        ]);
        $valid = $form->is_validated();
        $this->assertTrue($valid, 'Did not validate: ' . json_encode($this->mform($form)->_errors));
        $form->process_dynamic_submission();

        $values = \local_groupdist\local\fields::get_group_values([(int) $group->id]);
        $this->assertSame(17, (int) $values[(int) $group->id]->seats);
        $this->assertSame('Lab 1', $values[(int) $group->id]->location);
    }
}
