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

/**
 * The options and preview controller (distribute.php) run as a request would run it.
 *
 * A page script has no class to cover. The seed is what these tests follow:
 * every recompute hides the memberships stamped with its own seed, so a seed
 * whose run already wrote must not carry into a new plan.
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
final class distribute_page_test extends \advanced_testcase {
    /**
     * Going back after an aborted run that wrote memberships, then applying a
     * changed plan, never puts a participant in a second group.
     *
     * An earlier attempt placed two participants in different groups; a third
     * enrolled afterwards and the run was aborted as stale. Under the old seed
     * a keep-together rule would plan all three as ungrouped and add one of
     * the first two to the other's group, which the precondition shows. The
     * third participant is the control that the apply POST really wrote.
     * Changes that must make it fail: dropping the spent-seed check from
     * distribute.php. The aborted arm of runlog::is_seed_spent() and its
     * membership check both see this run, so dropping either one alone
     * leaves it green; runlog_test pins each of them.
     *
     * @return void
     */
    public function test_back_after_an_aborted_run_that_wrote_cannot_place_a_user_twice(): void {
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

        // The interrupted attempt wrote both memberships, then the run was aborted.
        $distribution = distribution::build(options::from_array($base), $context);
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        applier::apply($distribution, null, $runid);
        $third = (int) $generator->create_and_enrol($course, 'student', ['city' => 'Recife'])->id;
        $this->assertSame(2, runlog::abort($runid));
        $this->assertCount(1, $this->groups_of($first, [$alpha, $beta]));
        $this->assertCount(1, $this->groups_of($second, [$alpha, $beta]));
        $this->assertNotSame($this->groups_of($first, [$alpha, $beta]), $this->groups_of($second, [$alpha, $beta]));

        $changed = $base + ['affinityrules' => [['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]]];
        // Precondition: under the old seed the changed plan writes all three into one group.
        $stale = distribution::build(options::from_array($changed), $context);
        $this->assertSame(3, $stale->allocation->count_memberships());

        $html = $this->post_distribute($this->flatten(options::from_array($base)) + ['back' => 1, 'id' => $course->id]);
        $seed = $this->form_seed($html);
        $this->assertNotSame(4242, $seed);

        $changed['seed'] = $seed;
        $rerun = options::from_array($changed);
        $this->post_apply($this->flatten($rerun) + ['fingerprint' => distribution::build($rerun, $context)->fingerprint]);

        $this->assertCount(1, $this->groups_of($third, [$alpha, $beta]), 'The apply POST wrote nothing.');
        $this->assertCount(1, $this->groups_of($first, [$alpha, $beta]), 'A participant was placed in a second group.');
        $this->assertCount(1, $this->groups_of($second, [$alpha, $beta]), 'A participant was placed in a second group.');
        $this->assertSame(2, $DB->count_records('local_groupdist_run', ['courseid' => $course->id]));
    }

    /**
     * Going back after an interrupted apply that wrote memberships, then
     * applying a changed plan, never puts a participant in a second group.
     *
     * The same hazard as the aborted case, while the run is still pending: an
     * inline apply died before runlog::complete(), so memberswritten is 0 and
     * only the stamped memberships show that the seed wrote. The third
     * participant is the control that the apply POST really wrote, which also
     * keeps the test red when apply.php's own spent-seed refusal is what
     * stops the double placement. Changes that must make it fail: dropping the
     * spent-seed check from distribute.php, or the membership check of
     * runlog::is_seed_spent().
     *
     * @return void
     */
    public function test_back_after_an_interrupted_apply_that_wrote_cannot_place_a_user_twice(): void {
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

        // The interrupted apply wrote both memberships and never sealed its run.
        $distribution = distribution::build(options::from_array($base), $context);
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        applier::apply($distribution, null, $runid);
        $third = (int) $generator->create_and_enrol($course, 'student', ['city' => 'Recife'])->id;
        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], 'status, memberswritten', MUST_EXIST);
        $this->assertSame(runlog::STATUS_PENDING, (int) $run->status);
        $this->assertSame(0, (int) $run->memberswritten);
        $this->assertCount(1, $this->groups_of($first, [$alpha, $beta]));
        $this->assertCount(1, $this->groups_of($second, [$alpha, $beta]));
        $this->assertNotSame($this->groups_of($first, [$alpha, $beta]), $this->groups_of($second, [$alpha, $beta]));

        $changed = $base + ['affinityrules' => [['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]]];
        // Precondition: under the old seed the changed plan writes all three into one group.
        $stale = distribution::build(options::from_array($changed), $context);
        $this->assertSame(3, $stale->allocation->count_memberships());

        $html = $this->post_distribute($this->flatten(options::from_array($base)) + ['back' => 1, 'id' => $course->id]);
        $seed = $this->form_seed($html);
        $this->assertNotSame(4242, $seed);

        $changed['seed'] = $seed;
        $rerun = options::from_array($changed);
        $this->post_apply($this->flatten($rerun) + ['fingerprint' => distribution::build($rerun, $context)->fingerprint]);

        $this->assertCount(1, $this->groups_of($third, [$alpha, $beta]), 'The apply POST wrote nothing.');
        $this->assertCount(1, $this->groups_of($first, [$alpha, $beta]), 'A participant was placed in a second group.');
        $this->assertCount(1, $this->groups_of($second, [$alpha, $beta]), 'A participant was placed in a second group.');
        $this->assertSame(2, $DB->count_records('local_groupdist_run', ['courseid' => $course->id]));
    }

    /**
     * A resumable background run is left to finish under its own seed while
     * the options page moves on to a fresh one.
     *
     * The first attempt of the task wrote one membership, which spends the
     * seed for a new plan (the precondition), yet the retried task must still
     * recompute under that seed, match its fingerprint and complete. This is
     * the control for the membership check of runlog::is_seed_spent(): the
     * check decides a new plan's seed and never the task's own recompute.
     *
     * @return void
     */
    public function test_back_during_a_resumable_run_leaves_the_run_to_finish(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = (int) $generator->create_group(['courseid' => $course->id])->id;
        $generator->create_and_enrol($course);
        $generator->create_and_enrol($course);
        $options = options::from_array(['courseid' => $course->id, 'groupids' => [$group], 'seed' => 11]);
        $distribution = distribution::build($options, $context);
        $runid = runlog::create($distribution, (int) get_admin()->id, $context);
        $task = \local_groupdist\task\apply_distribution::create($options, $distribution->fingerprint, $runid);
        $task->set_userid(get_admin()->id);
        $task->set_id(\core\task\manager::queue_adhoc_task($task));
        $task->initialise_stored_progress();

        // The first attempt wrote one membership, then died.
        groups_add_member($group, (int) $distribution->allocation->assignments[$group][0], 'local_groupdist', 11);
        $this->assertTrue(runlog::is_seed_spent((int) $course->id, 11));

        $html = $this->post_distribute($this->flatten($options) + ['back' => 1, 'id' => $course->id]);
        $this->assertNotSame(11, $this->form_seed($html));

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/applied distribution/');
        $task->execute();
        $sink->close();

        $this->assertSame(2, $DB->count_records('groups_members', ['groupid' => $group]));
        $this->assertSame(runlog::STATUS_COMPLETED, (int) $DB->get_field('local_groupdist_run', 'status', ['id' => $runid]));
    }

    /**
     * Going back keeps a seed no run has written under, and replaces one whose
     * run completed.
     *
     * The kept seeds are the controls: a page that minted a seed on every
     * "Back" would pass the completed case alone. Changes that must make it
     * fail: dropping the spent-seed check from distribute.php, or counting an
     * aborted run that wrote nothing as spent.
     *
     * @return void
     */
    public function test_back_replaces_only_a_seed_whose_run_wrote(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = (int) $generator->create_group(['courseid' => $course->id])->id;
        $generator->create_and_enrol($course);
        $back = function (int $seed) use ($course, $group): int {
            $options = options::from_array(['courseid' => $course->id, 'groupids' => [$group], 'seed' => $seed]);
            return $this->form_seed($this->post_distribute($this->flatten($options) + ['back' => 1, 'id' => $course->id]));
        };
        $run = function (int $seed) use ($course, $group, $context): int {
            $options = options::from_array(['courseid' => $course->id, 'groupids' => [$group], 'seed' => $seed]);
            return runlog::create(distribution::build($options, $context), (int) get_admin()->id, $context);
        };

        $this->assertSame(101, $back(101), 'A seed without a run was replaced.');

        runlog::abort($run(102));
        $this->assertSame(102, $back(102), 'An aborted run that wrote nothing spent its seed.');

        runlog::complete($run(103), ['added' => 1, 'failed' => 0]);
        $this->assertNotSame(103, $back(103), 'A completed run left its seed reusable.');
    }

    /**
     * A preview submitted with a spent seed is computed under a fresh one, and
     * a preview under an unspent seed keeps it.
     *
     * This is the route that bypasses "Back": an options form already on
     * screen (a second tab, a page restored from the browser cache) still
     * carries the old seed. Changes that must make it fail: building the
     * preview options from the submitted seed rather than the checked one.
     *
     * @return void
     */
    public function test_a_preview_never_runs_under_a_spent_seed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = (int) $generator->create_group(['courseid' => $course->id])->id;
        $generator->create_and_enrol($course);
        $preview = function (int $seed) use ($course, $group): int {
            \local_groupdist\form\options_form::mock_submit([
                'id' => (int) $course->id,
                'groupids' => (string) $group,
                'seed' => $seed,
                'roleid' => 0,
                'allocateby' => options::ALLOCATE_RANDOM,
                'ignoregrouped' => 1,
                'includeonlyactiveenrol' => 1,
                'useseats' => 0,
                'overbook' => 0,
                'previewbutton' => 1,
            ]);
            try {
                $html = $this->render_distribute();
            } finally {
                $_POST = [];
            }
            // The sticky footer carries the seed into both the apply and the back forms.
            $this->assertSame(2, preg_match_all('/<input type="hidden" name="seed" value="(\d+)">/', $html, $matches));
            $this->assertSame($matches[1][0], $matches[1][1]);
            return (int) $matches[1][0];
        };

        $this->assertSame(201, $preview(201), 'An unspent seed was replaced.');

        $options = options::from_array(['courseid' => $course->id, 'groupids' => [$group], 'seed' => 202]);
        $runid = runlog::create(distribution::build($options, $context), (int) get_admin()->id, $context);
        runlog::complete($runid, ['added' => 1, 'failed' => 0]);
        $this->assertNotSame(202, $preview(202), 'A preview ran under a spent seed.');
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
     * The request parameters the preview's footer forms post for a set of options.
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
     * The seed held by the options form's hidden field.
     *
     * @param string $html The rendered step 1 page.
     * @return int The seed.
     */
    private function form_seed(string $html): int {
        $this->assertSame(1, preg_match('/<input[^>]*\bname="seed"[^>]*>/', $html, $input));
        $this->assertSame(1, preg_match('/\bvalue="(\d+)"/', $input[0], $value));
        return (int) $value[1];
    }

    /**
     * POST to distribute.php as the current user and return the page.
     *
     * @param array $post The request parameters, sesskey excluded.
     * @return string The page HTML.
     */
    private function post_distribute(array $post): string {
        $_POST = $post + ['sesskey' => sesskey()];
        try {
            return $this->render_distribute();
        } finally {
            $_POST = [];
        }
    }

    /**
     * Run distribute.php against the current request parameters.
     *
     * @return string The page HTML.
     */
    private function render_distribute(): string {
        // The page runs in this method's scope and reads these as its globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SESSION, $SITE, $USER;

        $PAGE = new \moodle_page();
        $OUTPUT = new \bootstrap_renderer();
        ob_start();
        try {
            require($CFG->dirroot . '/local/groupdist/distribute.php');
        } finally {
            $html = ob_get_clean();
        }
        return $html;
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
