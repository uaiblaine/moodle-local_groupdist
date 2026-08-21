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

namespace local_groupdist\backup;

use local_groupdist\local\distribution;
use local_groupdist\local\options;
use local_groupdist\local\runlog;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Audit log backup/restore round trip: the settings gate, the id remapping
 * and the pseudonymisation of rows whose user did not travel.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\backup_local_groupdist_plugin::class)]
#[CoversClass(\restore_local_groupdist_plugin::class)]
final class backup_restore_test extends \advanced_testcase {
    /**
     * Seed a course with two groups, four participants and one completed run.
     *
     * The applier is the admin (not enrolled), which exercises the user
     * annotation: without it the applier would not travel in the backup.
     *
     * @return array [course record, run id, participant user ids].
     */
    private function seed(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group1 = $generator->create_group(['courseid' => $course->id, 'name' => 'Alpha']);
        $group2 = $generator->create_group(['courseid' => $course->id, 'name' => 'Beta']);

        $userids = [];
        foreach (['X', 'X', 'Y', 'Y'] as $city) {
            $user = $generator->create_and_enrol($course, 'student', ['city' => $city]);
            $userids[] = (int) $user->id;
        }

        $this->setAdminUser();
        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group1->id, (int) $group2->id],
            'affinityrules' => [
                ['source' => 'city', 'mode' => options::AFFINITY_TOGETHER],
            ],
            'roleid' => (int) current(get_archetype_roles('student'))->id,
            'seed' => 42,
        ]);
        $distribution = distribution::build($options, $context);
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        runlog::complete($runid, ['added' => 4, 'failed' => 0]);
        return [$course, (int) $runid, $userids];
    }

    /**
     * Back the course up in import mode (plain directory, no zip).
     *
     * @param \stdClass $course The course.
     * @param bool $logs Value for the root logs setting.
     * @param bool $anonymize Value for the root anonymize setting.
     * @return string The backup id (also the temp directory name).
     */
    private function backup_course(\stdClass $course, bool $logs = true, bool $anonymize = false): string {
        global $CFG, $USER;

        // Turn off file logging, otherwise it can't delete the file (Windows).
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->get_plan()->get_setting('logs')->set_value($logs);
        $bc->get_plan()->get_setting('anonymize')->set_value($anonymize);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();
        return $backupid;
    }

    /**
     * Restore a backup into a fresh course.
     *
     * @param string $backupid The backup id.
     * @param \stdClass $course The source course (names the copy).
     * @param string $suffix Shortname suffix for the new course.
     * @param bool $logs Value for the restore-side logs setting.
     * @return int The new course id.
     */
    private function restore_course(string $backupid, \stdClass $course, string $suffix, bool $logs = true): int {
        global $USER;

        $newcourseid = \restore_dbops::create_new_course(
            $course->fullname . $suffix,
            $course->shortname . $suffix,
            $course->category
        );
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value(true);
        $rc->get_plan()->get_setting('logs')->set_value($logs);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return (int) $newcourseid;
    }

    /**
     * The generated course.xml of a backup.
     *
     * @param string $backupid The backup id.
     * @return string The XML.
     */
    private function course_xml(string $backupid): string {
        global $CFG;
        return file_get_contents($CFG->backuptempdir . '/' . $backupid . '/course/course.xml');
    }

    /**
     * Full round trip: the run is recreated in the target course with the
     * applier and participants remapped, the group references (row and
     * snapshot alike) pointing at the restored groups, and the restored flag
     * set; everything else is byte-identical to the source snapshot.
     */
    public function test_roundtrip_restores_the_audit_snapshot(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $runid, $userids] = $this->seed();

        $backupid = $this->backup_course($course);
        $newcourseid = $this->restore_course($backupid, $course, '_rt');

        $runs = $DB->get_records('local_groupdist_run', ['courseid' => $newcourseid]);
        $this->assertCount(1, $runs);
        $run = reset($runs);
        $oldrun = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);

        $this->assertSame(1, (int) $run->restored);
        // The applier is not enrolled: only the annotation carries them over.
        $this->assertSame((int) get_admin()->id, (int) $run->userid);
        $this->assertSame($oldrun->fingerprint, $run->fingerprint);
        $this->assertSame((int) $oldrun->seed, (int) $run->seed);
        $this->assertSame((int) $oldrun->status, (int) $run->status);
        $this->assertSame((int) $oldrun->memberstotal, (int) $run->memberstotal);
        $this->assertSame((int) $oldrun->memberswritten, (int) $run->memberswritten);
        $this->assertSame((int) $oldrun->timecreated, (int) $run->timecreated);
        $this->assertSame($oldrun->rulesjson, $run->rulesjson);
        $this->assertSame($oldrun->optionsjson, $run->optionsjson);

        // The snapshot keys moved to the restored groups' ids, names intact.
        $newbyname = [];
        foreach (groups_get_all_groups($newcourseid) as $group) {
            $newbyname[$group->name] = (int) $group->id;
        }
        $snapshot = json_decode($run->groupsjson, true);
        $oldsnapshot = json_decode($oldrun->groupsjson, true);
        $this->assertCount(2, $snapshot);
        foreach ($snapshot as $i => $group) {
            $this->assertSame($newbyname[$group['name']], (int) $group['id']);
            $this->assertNotEquals((int) $oldsnapshot[$i]['id'], (int) $group['id']);
            $this->assertSame($oldsnapshot[$i]['name'], $group['name']);
            $this->assertSame($oldsnapshot[$i]['current'], $group['current']);
        }

        // Participant rows: same users (samesite match), values and outcomes
        // preserved, planned group remapped consistently with the snapshot.
        $oldbyname = [];
        foreach (groups_get_all_groups((int) $course->id) as $group) {
            $oldbyname[(int) $group->id] = $group->name;
        }
        $oldrows = array_values($DB->get_records('local_groupdist_run_user', ['runid' => $runid], 'id'));
        $rows = array_values($DB->get_records('local_groupdist_run_user', ['runid' => $run->id], 'id'));
        $this->assertCount(count($oldrows), $rows);
        $this->assertCount(4, $rows);
        foreach ($rows as $i => $row) {
            $this->assertSame((int) $oldrows[$i]->userid, (int) $row->userid);
            $this->assertContains((int) $row->userid, $userids);
            $this->assertSame($oldrows[$i]->valuesjson, $row->valuesjson);
            $this->assertSame((int) $oldrows[$i]->writestatus, (int) $row->writestatus);
            $oldgroupname = $oldbyname[(int) $oldrows[$i]->groupid];
            $this->assertSame($newbyname[$oldgroupname], (int) $row->groupid);
        }

        // The source course keeps its own run untouched.
        $this->assertSame(1, $DB->count_records('local_groupdist_run', ['courseid' => (int) $course->id]));
    }

    /**
     * The backup gate: with the logs setting off the audit never enters the
     * backup file. The control is the artifact itself — the same backup with
     * logs on does contain the plugin subtree (asserted in the round-trip
     * test via restored rows and here at the XML level before restoring).
     */
    public function test_backup_without_logs_excludes_the_audit(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->seed();

        $backupid = $this->backup_course($course, false);
        $this->assertStringNotContainsString('plugin_local_groupdist_course', $this->course_xml($backupid));

        $newcourseid = $this->restore_course($backupid, $course, '_nl');
        // Control: the restore pipeline itself ran — the groups arrived.
        $this->assertCount(2, groups_get_all_groups($newcourseid));
        $this->assertSame(0, $DB->count_records('local_groupdist_run', ['courseid' => $newcourseid]));
    }

    /**
     * The anonymize gate: an anonymised backup carries no audit even with
     * logs on — the snapshot values would deanonymise the participants.
     */
    public function test_anonymised_backup_excludes_the_audit(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->seed();

        $backupid = $this->backup_course($course, true, true);
        $this->assertStringNotContainsString('plugin_local_groupdist_course', $this->course_xml($backupid));

        $newcourseid = $this->restore_course($backupid, $course, '_an');
        // Control: the restore pipeline itself ran — the groups arrived.
        $this->assertCount(2, groups_get_all_groups($newcourseid));
        $this->assertSame(0, $DB->count_records('local_groupdist_run', ['courseid' => $newcourseid]));
    }

    /**
     * The restore-side gate: the backup carries the audit (asserted at the
     * XML level — the non-vacuity control), but a restore with logs off
     * skips it.
     */
    public function test_restore_without_logs_skips_the_audit(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->seed();

        $backupid = $this->backup_course($course);
        $coursexml = $this->course_xml($backupid);
        $this->assertStringContainsString('plugin_local_groupdist_course', $coursexml);
        $this->assertStringContainsString('<runuser id=', $coursexml);

        $newcourseid = $this->restore_course($backupid, $course, '_rg', false);
        $this->assertCount(2, groups_get_all_groups($newcourseid));
        $this->assertSame(0, $DB->count_records('local_groupdist_run', ['courseid' => $newcourseid]));
    }

    /**
     * Rows pseudonymised before the backup stay pseudonymised through the
     * round trip: userid 0 maps to nothing and the values stay blanked.
     */
    public function test_roundtrip_keeps_pseudonymised_rows(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $runid, $userids] = $this->seed();
        runlog::pseudonymise_user($userids[0]);

        $backupid = $this->backup_course($course);
        $newcourseid = $this->restore_course($backupid, $course, '_ps');

        $runs = $DB->get_records('local_groupdist_run', ['courseid' => $newcourseid]);
        $run = reset($runs);
        $rows = array_values($DB->get_records('local_groupdist_run_user', ['runid' => $run->id], 'id'));
        $this->assertCount(4, $rows);
        $pseudonymised = array_values(array_filter($rows, function (\stdClass $row): bool {
            return (int) $row->userid === 0;
        }));
        $this->assertCount(1, $pseudonymised);
        $this->assertSame('', $pseudonymised[0]->valuesjson);
        // The other participants still map to their users.
        foreach ($rows as $row) {
            if ((int) $row->userid !== 0) {
                $this->assertContains((int) $row->userid, $userids);
                $this->assertNotSame('', $row->valuesjson);
            }
        }
    }
}
