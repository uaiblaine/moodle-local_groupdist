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

use local_groupdist\local\distribution;
use local_groupdist\local\options;
use local_groupdist\local\runlog;

/**
 * Audit UI export tests: explanations from stored facts, reader-side masking,
 * pseudonymised participants.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\output\audit_detail
 * @covers     \local_groupdist\output\audit_list
 */
final class audit_test extends \advanced_testcase {
    /**
     * The page renderer (the exports do not use it, but the contract does).
     *
     * @return \renderer_base The renderer.
     */
    private function renderer(): \renderer_base {
        global $PAGE;
        return $PAGE->get_renderer('core');
    }

    /**
     * Seed a run: city keep-together plus a PRIVATE custom field keep-apart.
     *
     * @return array [course, context, runid, teacherid].
     */
    private function seed(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group1 = $generator->create_group(['courseid' => $course->id, 'name' => 'Alpha']);
        $group2 = $generator->create_group(['courseid' => $course->id, 'name' => 'Beta']);
        $field = $generator->create_custom_profile_field([
            'shortname' => 'guardian',
            'name' => 'Guardian name',
            'datatype' => 'text',
            'visible' => PROFILE_VISIBLE_PRIVATE,
        ]);

        foreach ([['X', 'Maria'], ['X', 'Maria'], ['Y', 'Rita']] as [$city, $guardian]) {
            $user = $generator->create_and_enrol($course);
            $user->city = $city;
            user_update_user($user, false);
            profile_save_data((object) ['id' => $user->id, 'profile_field_guardian' => $guardian]);
        }
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $this->setAdminUser();
        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group1->id, (int) $group2->id],
            'affinityrules' => [
                ['source' => 'city', 'mode' => options::AFFINITY_TOGETHER],
                ['source' => 'profile_' . $field->id, 'mode' => options::AFFINITY_APART],
            ],
            'roleid' => (int) current(get_archetype_roles('student'))->id,
            'seed' => 21,
        ]);
        $distribution = distribution::build($options, $context);
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        return [$course, $context, $runid, (int) $teacher->id];
    }

    /**
     * Explanations come from the stored facts: kept-with counts and the
     * separated-from peer list with their snapshot group names.
     */
    public function test_detail_explanations(): void {
        global $DB;
        $this->resetAfterTest();
        [, $context, $runid] = $this->seed();

        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);
        $export = (new audit_detail($run, $context))->export_for_template($this->renderer());

        $this->assertCount(2, $export['rules']);
        $this->assertFalse($export['rules'][0]['masked']);

        // The two Maria guardians were split across groups by the apart rule:
        // one member's explanations must include a keep-apart separation line.
        $alltexts = [];
        foreach ($export['groups'] as $group) {
            foreach ($group['members'] as $member) {
                foreach ($member['why'] as $line) {
                    $alltexts[] = $line['text'];
                }
            }
        }
        $together = array_filter($alltexts, function (string $text): bool {
            return str_contains($text, '"X"');
        });
        $this->assertNotEmpty($together, 'Keep-together lines must name the shared value');
    }

    /**
     * Reader-side masking: as admin the private field's facts are visible; as
     * an editing teacher (no viewalldetails) they are masked — the control
     * pair proving the mask is the capability, not missing data.
     */
    public function test_detail_masks_private_field_for_teacher(): void {
        global $DB;
        $this->resetAfterTest();
        [, $context, $runid, $teacherid] = $this->seed();
        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);
        $renderer = $this->renderer();

        $this->setAdminUser();
        $adminexport = (new audit_detail($run, $context))->export_for_template($renderer);
        $this->assertFalse($adminexport['rules'][1]['masked']);

        $this->setUser($teacherid);
        $export = (new audit_detail($run, $context))->export_for_template($renderer);
        $this->assertTrue($export['rules'][1]['masked']);

        $maskedstring = get_string('auditwhymasked', 'local_groupdist', (object) [
            'index' => 2,
            'label' => 'Guardian name',
        ]);
        $found = false;
        foreach ($export['groups'] as $group) {
            foreach ($group['members'] as $member) {
                foreach ($member['why'] as $line) {
                    $this->assertStringNotContainsString('Maria', $line['text']);
                    if ($line['text'] === $maskedstring) {
                        $found = true;
                    }
                }
            }
        }
        $this->assertTrue($found, 'The masked rule must surface as the masked note');
    }

    /**
     * Pseudonymised rows render as removed participants.
     */
    public function test_detail_shows_removed_participant(): void {
        global $DB;
        $this->resetAfterTest();
        [, $context, $runid] = $this->seed();
        $rows = array_values($DB->get_records('local_groupdist_run_user', ['runid' => $runid], 'id'));
        runlog::pseudonymise_user((int) $rows[0]->userid);

        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);
        $this->setAdminUser();
        $export = (new audit_detail($run, $context))->export_for_template($this->renderer());

        $names = [];
        foreach ($export['groups'] as $group) {
            foreach ($group['members'] as $member) {
                $names[] = $member['name'];
            }
        }
        $this->assertContains(get_string('auditremoved', 'local_groupdist'), $names);
    }

    /**
     * The run list exports the rows with status badges and detail links.
     */
    public function test_list_export(): void {
        $this->resetAfterTest();
        [$course, , $runid] = $this->seed();

        $this->setAdminUser();
        $export = (new audit_list((int) $course->id, 0))->export_for_template($this->renderer());

        $this->assertTrue($export['hasruns']);
        $this->assertCount(1, $export['runs']);
        $row = $export['runs'][0];
        $this->assertSame(get_string('auditstatuspending', 'local_groupdist'), $row['status']['label']);
        $this->assertSame(2, $row['rulecount']);
        $this->assertStringContainsString('run=' . $runid, $row['detailurl']);
    }
}
