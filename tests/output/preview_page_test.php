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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Preview page recap tests.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\output\preview_page::class)]
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

    /**
     * The recap names the filters that will actually run, not the ones the
     * form posted.
     *
     * candidates::fetch() forces only-active ON for anyone without
     * moodle/course:viewsuspendedusers, and makes the future-start relaxation
     * inert for them — so the recap used to omit the single filter that had
     * narrowed their candidate list. That matters most in the state where the
     * list came back empty, because the explanation points at this recap.
     *
     * Mutation: drop the capability mirroring in export_for_template() and
     * rows 1 and 2 below stop differing.
     *
     * @return void
     */
    public function test_the_recap_shows_the_filters_that_will_actually_run(): void {
        global $PAGE;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'nosuspended']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $roleid);
        role_change_permission($roleid, $context, 'moodle/course:viewsuspendedusers', CAP_PROHIBIT);

        $PAGE->set_url('/local/groupdist/distribute.php');
        $PAGE->set_context($context);
        $chips = function (int $onlyactive, int $includefuture) use ($course, $group, $context, $PAGE): string {
            $options = options::from_array([
                'courseid' => (int) $course->id,
                'groupids' => [(int) $group->id],
                'onlyactive' => $onlyactive,
                'includefuture' => $includefuture,
            ]);
            $data = (new preview_page($options, $context, []))
                ->export_for_template($PAGE->get_renderer('core'));
            $texts = [];
            foreach ($data['recap'] as $section) {
                foreach ($section['items'] as $item) {
                    $texts[] = $item['text'];
                }
            }
            return implode(' | ', $texts);
        };

        $onlyactivelabel = get_string('includeonlyactiveenrol', 'group');
        $futurelabel = get_string('includefutureenrol', 'local_groupdist');

        /* Row 1 — no capability. The form cannot even render the only-active
           checkbox for this teacher, so distribute.php posts 0; the candidate
           query forces it back on, and the recap has to say so. A posted
           future-start flag is inert for them and must not be advertised. */
        $this->setUser($teacher);
        $this->assertFalse(has_capability('moodle/course:viewsuspendedusers', $context));
        $forced = $chips(0, 1);
        $this->assertStringContainsString($onlyactivelabel, $forced, 'The forced filter is not named.');
        $this->assertStringNotContainsString($futurelabel, $forced, 'An inert filter is reported as active.');

        /* Row 2 — same input, with the capability. Only-active is genuinely
           off now, so the chip must be gone: this is what proves row 1 is
           reporting the forcing rather than always printing the chip. */
        $this->setAdminUser();
        $this->assertTrue(has_capability('moodle/course:viewsuspendedusers', $context));
        $this->assertStringNotContainsString($onlyactivelabel, $chips(0, 1));

        // Row 3 — future-start is reported once it can actually apply.
        $this->assertStringContainsString($futurelabel, $chips(1, 1));
    }
}
