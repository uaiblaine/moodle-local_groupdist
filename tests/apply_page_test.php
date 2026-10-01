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

use local_groupdist\local\distribution;
use local_groupdist\local\options;
use local_groupdist\local\runlog;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The apply endpoint (apply.php) run as a request would run it.
 *
 * A page script has no class to cover. Every outcome of apply.php ends in a
 * redirect, which throws under PHPUnit before its message is stored, so these
 * tests judge it by what it wrote.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class apply_page_test extends \advanced_testcase {
    /**
     * A replayed apply POST after a completed run records no second run,
     * while the same POST after an interrupted inline apply (its run still
     * pending) is applied.
     *
     * The first POST is the control that the payload is well formed and its
     * fingerprint matches; the pending case shows the refusal comes from the
     * completed run and not from a stale fingerprint. Changes that must make
     * it fail: deleting the runlog::is_applied() guard from apply.php.
     *
     * @return void
     */
    public function test_a_replayed_post_is_refused_only_after_a_completed_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_and_enrol($course);
        $generator->create_and_enrol($course);
        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'ignoregrouped' => 1,
            'onlyactive' => 1,
            'seed' => 77,
        ]);
        $post = ['fingerprint' => distribution::build($options, $context)->fingerprint];
        foreach ($options->to_array() as $name => $value) {
            if ($name === 'affinityrules') {
                continue;
            }
            $post[$name] = $value;
        }
        $runs = ['courseid' => $course->id, 'seed' => 77];

        $this->post_apply($post);
        $this->assertSame(1, $DB->count_records('local_groupdist_run', $runs));
        $this->assertSame(2, $DB->count_records('groups_members', ['groupid' => $group->id]));
        $runid = (int) $DB->get_field('local_groupdist_run', 'id', $runs);
        $this->assertSame(runlog::STATUS_COMPLETED, (int) $DB->get_field('local_groupdist_run', 'status', ['id' => $runid]));

        // The replay: back button and resubmit.
        $this->post_apply($post);
        $this->assertSame(1, $DB->count_records('local_groupdist_run', $runs), 'A replayed POST recorded a second run.');

        // An inline apply interrupted before runlog::complete() stays retryable.
        $DB->set_field('local_groupdist_run', 'status', runlog::STATUS_PENDING, ['id' => $runid]);
        $this->post_apply($post);
        $this->assertSame(2, $DB->count_records('local_groupdist_run', $runs));
        $this->assertSame(2, $DB->count_records('groups_members', ['groupid' => $group->id]));
    }

    /**
     * POST to apply.php as the current user and let it run to its redirect.
     *
     * @param array $post The request parameters, sesskey excluded.
     * @return void
     */
    private function post_apply(array $post): void {
        // The page runs in this method's scope and reads these as its globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SESSION, $SITE, $USER;

        $PAGE = new \moodle_page();
        $_POST = $post + ['sesskey' => sesskey()];
        try {
            require($CFG->dirroot . '/local/groupdist/apply.php');
            $this->fail('apply.php ended without a redirect.');
        } catch (\moodle_exception $e) {
            // Under PHPUnit, redirect() throws instead of sending a Location header.
            $this->assertSame('redirecterrordetected', $e->errorcode);
        } finally {
            $_POST = [];
        }
    }
}
