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

use local_groupdist\local\applier;
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
     * Retrying an interrupted inline apply that wrote nothing is applied, and
     * replaying the POST once that run completed records no second run.
     *
     * The retry is the control that the payload is well formed and its
     * fingerprint matches, and that a pending run alone does not refuse: the
     * interrupted apply died before its first membership. Changes that must
     * make it fail: deleting the spent-seed refusal from apply.php, or
     * counting a pending run that wrote nothing as spent.
     *
     * @return void
     */
    public function test_a_replayed_post_is_refused_once_its_run_completed(): void {
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
        $distribution = distribution::build($options, $context);
        $post = $this->flatten($options) + ['fingerprint' => $distribution->fingerprint];
        $runs = ['courseid' => $course->id, 'seed' => 77];

        // An inline apply interrupted before its first membership: the run is pending and nothing was written.
        runlog::create($distribution, (int) get_admin()->id, $context);
        $this->assertSame(0, $DB->count_records('groups_members', ['groupid' => $group->id]));

        $this->post_apply($post);
        $this->assertSame(2, $DB->count_records('local_groupdist_run', $runs), 'The retry was refused.');
        $this->assertSame(2, $DB->count_records('groups_members', ['groupid' => $group->id]));
        $this->assertSame(1, $DB->count_records('local_groupdist_run', $runs + ['status' => runlog::STATUS_COMPLETED]));

        // The replay: back button and resubmit.
        $this->post_apply($post);
        $this->assertSame(2, $DB->count_records('local_groupdist_run', $runs), 'A replayed POST recorded another run.');
    }

    /**
     * A POST under a seed that already wrote memberships is refused, even
     * while the run that wrote them is still pending.
     *
     * An inline apply placed two participants in different groups and died
     * before sealing its run; a third enrolled afterwards. A plan previewed
     * under the same seed with a keep-together rule still matches its
     * fingerprint, because every recompute hides that seed's own memberships,
     * and would add one of the first two to the other's group, which the
     * precondition shows. The same options under a fresh seed are the
     * control: that POST is applied, places the third participant and nobody
     * twice. Changes that must make it fail: deleting the spent-seed refusal
     * from apply.php, or the membership check of runlog::is_seed_spent().
     *
     * @return void
     */
    public function test_a_post_under_a_seed_that_wrote_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $alpha = (int) $generator->create_group(['courseid' => $course->id, 'name' => 'Alpha'])->id;
        $beta = (int) $generator->create_group(['courseid' => $course->id, 'name' => 'Beta'])->id;
        $first = (int) $generator->create_and_enrol($course, 'student', ['city' => 'Recife'])->id;
        $second = (int) $generator->create_and_enrol($course, 'student', ['city' => 'Recife'])->id;
        $base = [
            'courseid' => $course->id,
            'groupids' => [$alpha, $beta],
            'ignoregrouped' => 1,
            'onlyactive' => 1,
            'seed' => 4242,
        ];

        // The interrupted inline apply wrote both memberships and never sealed its run.
        $distribution = distribution::build(options::from_array($base), $context);
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        applier::apply($distribution, null, $runid);
        $third = (int) $generator->create_and_enrol($course, 'student', ['city' => 'Recife'])->id;
        $this->assertSame(runlog::STATUS_PENDING, (int) $DB->get_field('local_groupdist_run', 'status', ['id' => $runid]));
        $this->assertNotSame($this->groups_of($first, [$alpha, $beta]), $this->groups_of($second, [$alpha, $beta]));

        $changed = options::from_array($base + [
            'affinityrules' => [['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]],
        ]);
        $stale = distribution::build($changed, $context);
        // Precondition: under the old seed the changed plan writes all three into one group.
        $this->assertSame(3, $stale->allocation->count_memberships());

        $this->post_apply($this->flatten($changed) + ['fingerprint' => $stale->fingerprint]);
        $this->assertCount(1, $this->groups_of($first, [$alpha, $beta]), 'A participant was placed in a second group.');
        $this->assertCount(1, $this->groups_of($second, [$alpha, $beta]), 'A participant was placed in a second group.');
        $this->assertCount(0, $this->groups_of($third, [$alpha, $beta]), 'The POST wrote a membership.');
        $this->assertSame(1, $DB->count_records('local_groupdist_run', ['courseid' => $course->id]), 'The POST recorded a run.');

        $changed->seed = 4343;
        $this->post_apply($this->flatten($changed) + ['fingerprint' => distribution::build($changed, $context)->fingerprint]);
        $this->assertSame(2, $DB->count_records('local_groupdist_run', ['courseid' => $course->id]));
        $this->assertCount(1, $this->groups_of($third, [$alpha, $beta]), 'The fresh seed was refused.');
        $this->assertCount(1, $this->groups_of($first, [$alpha, $beta]));
        $this->assertCount(1, $this->groups_of($second, [$alpha, $beta]));
    }

    /**
     * The groups among the given ones that a user belongs to.
     *
     * @param int $userid The user id.
     * @param array $groupids The group ids to look in.
     * @return array The matching group ids, ascending.
     */
    private function groups_of(int $userid, array $groupids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
        $found = $DB->get_fieldset_select('groups_members', 'groupid', "userid = :userid AND groupid {$insql}", $params + [
            'userid' => $userid,
        ]);
        $found = array_map('intval', $found);
        sort($found);
        return $found;
    }

    /**
     * The request parameters the preview's apply form posts for a set of options.
     *
     * @param options $options The options.
     * @return array Parameter name => value, the ruleset flattened as the page does.
     */
    private function flatten(options $options): array {
        $post = [];
        foreach ($options->to_array() as $name => $value) {
            if ($name === 'affinityrules') {
                foreach ($value as $i => $rule) {
                    $post['affinityrulesources'][$i] = $rule['source'];
                    $post['affinityrulemodes'][$i] = $rule['mode'];
                }
                continue;
            }
            $post[$name] = $value;
        }
        return $post;
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
