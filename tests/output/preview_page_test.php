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

use local_groupdist\local\options;

/**
 * Preview page recap tests.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\output\preview_page
 */
final class preview_page_test extends \advanced_testcase {
    /**
     * The recap chips name the cohort and the affinity rule, and both land in
     * a Mustache double stash (preview.mustache renders each chip's text with
     * one), so neither may arrive escaped.
     *
     * @return void
     */
    public function test_recap_chips_are_not_pre_escaped(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $cohort = $this->getDataGenerator()->create_cohort([
            'contextid' => \core\context\system::instance()->id,
            'name' => 'Ciencias & Letras',
        ]);
        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'ampf',
            'name' => 'Turno & Sala',
        ]);

        $PAGE->set_url('/local/groupdist/distribute.php');
        $PAGE->set_context($context);
        $options = options::from_array([
            'courseid' => (int) $course->id,
            'groupids' => [(int) $group->id],
            'cohortid' => (int) $cohort->id,
            'affinityrules' => [['source' => 'profile_' . $field->id, 'mode' => 'together']],
        ]);
        $page = new preview_page($options, $context, []);
        $data = $page->export_for_template($PAGE->get_renderer('core'));

        $texts = [];
        foreach ($data['recap'] as $section) {
            foreach ($section['items'] as $item) {
                $texts[] = $item['text'];
            }
        }
        $joined = implode(' | ', $texts);

        $this->assertStringContainsString('Ciencias & Letras', $joined);
        $this->assertStringContainsString('Turno & Sala', $joined);
        $this->assertStringNotContainsString('&amp;', $joined);
    }
}
