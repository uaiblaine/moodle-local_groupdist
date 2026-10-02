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

namespace local_groupdist;

/**
 * The bulk edit page (bulkedit.php) run as a request would run it.
 *
 * A page script has no class to cover; tests/output/bulkedit_page_test.php
 * covers the renderable it builds.
 *
 * PHPUnit metadata is written as docblock tags, never attributes: Moodle 4.5
 * runs PHPUnit 9, which reads only the tags, and its moodle-cs reports every
 * test of a class without a covers tag.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class bulkedit_script_test extends \advanced_testcase {
    /**
     * A selected group the user may not see is left off the page, also on a
     * cold core/coursehiddengroups cache, where groups_get_all_groups() would
     * return it ({@see \local_groupdist\local\distribution::get_destination_groups()}).
     *
     * create_group() warms that cache entry, so it is purged before each
     * render. Changes that must make it fail: resolving the selected groups in
     * bulkedit.php through groups_get_all_groups() again.
     *
     * @return void
     */
    public function test_a_hidden_group_stays_off_the_page_on_a_cold_cache(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $open = (int) $generator->create_group(['courseid' => $course->id, 'visibility' => GROUPS_VISIBILITY_ALL])->id;
        $hidden = (int) $generator->create_group(['courseid' => $course->id, 'visibility' => GROUPS_VISIBILITY_NONE])->id;
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/course:viewhiddengroups', CAP_PROHIBIT, $roleid, $context->id, true);

        $this->setUser($teacher);
        $this->assertFalse(has_capability('moodle/course:viewhiddengroups', $context));
        $html = $this->render_bulkedit((int) $course->id, [$open, $hidden]);
        $this->assertStringContainsString('<tr data-groupid="' . $open . '"', $html);
        $this->assertStringNotContainsString('<tr data-groupid="' . $hidden . '"', $html);

        // Control: a viewhiddengroups holder gets both rows.
        $this->setAdminUser();
        $html = $this->render_bulkedit((int) $course->id, [$open, $hidden]);
        $this->assertStringContainsString('<tr data-groupid="' . $open . '"', $html);
        $this->assertStringContainsString('<tr data-groupid="' . $hidden . '"', $html);
    }

    /**
     * Render bulkedit.php as the current user, POSTed from group/index.php,
     * with the core/coursehiddengroups cache cold.
     *
     * @param int $courseid The course id parameter.
     * @param array $groupids The selected group ids.
     * @return string The page HTML.
     */
    private function render_bulkedit(int $courseid, array $groupids): string {
        // The page runs in this method's scope and reads these as its globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SESSION, $SITE, $USER;

        \cache_helper::purge_by_definition('core', 'coursehiddengroups');
        $PAGE = new \moodle_page();
        $OUTPUT = new \bootstrap_renderer();
        $_POST = ['id' => $courseid, 'groups' => $groupids];
        ob_start();
        try {
            require($CFG->dirroot . '/local/groupdist/bulkedit.php');
        } finally {
            $html = ob_get_clean();
            $_POST = [];
        }
        return $html;
    }
}
