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

namespace local_groupdist\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Paged audit reader: windows, searches and the explanations they carry.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_groupdist\local\auditreader::class)]
final class auditreader_test extends \advanced_testcase {
    /**
     * Seed a run over the given groups and users.
     *
     * @param array $groupnames Names of the groups to distribute into.
     * @param array $users One entry per user: [lastname, city].
     * @param array $rules Affinity rules, in the options array shape.
     * @return array [course, context, run record].
     */
    private function seed(array $groupnames, array $users, array $rules = []): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);

        $groupids = [];
        foreach ($groupnames as $name) {
            $group = $generator->create_group(['courseid' => $course->id, 'name' => $name]);
            $groupids[] = (int) $group->id;
        }
        foreach ($users as [$lastname, $city]) {
            $user = $generator->create_and_enrol($course);
            $user->lastname = $lastname;
            $user->city = $city;
            user_update_user($user, false);
        }

        $this->setAdminUser();
        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => $groupids,
            'affinityrules' => $rules,
            'roleid' => (int) current(get_archetype_roles('student'))->id,
            'seed' => 42,
        ]);
        $runid = runlog::create(distribution::build($options, $context), (int) get_admin()->id, $context);
        return [$course, $context, $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST)];
    }

    /**
     * A list of [lastname, city] pairs.
     *
     * @param int $count How many.
     * @param string $city The city every one of them holds.
     * @param string $prefix Lastname prefix.
     * @return array The user specs.
     */
    private function users(int $count, string $city = 'Recife', string $prefix = 'Aluno'): array {
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $users[] = [$prefix . str_pad((string) $i, 2, '0', STR_PAD_LEFT), $city];
        }
        return $users;
    }

    /**
     * Sections are windowed: a run over more groups than fit on a page
     * reports the full total but hands back only one page of them.
     */
    public function test_sections_are_paged(): void {
        $this->resetAfterTest();
        $names = [];
        for ($i = 0; $i < 10; $i++) {
            $names[] = 'Turma ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        }
        [, $context, $run] = $this->seed($names, $this->users(4));
        $reader = new auditreader($run, $context);

        $first = $reader->get_sections('', '', 0, auditreader::SECTIONS_PER_PAGE);
        $this->assertSame(10, $first['total']);
        $this->assertCount(auditreader::SECTIONS_PER_PAGE, $first['sections']);

        $second = $reader->get_sections('', '', 1, auditreader::SECTIONS_PER_PAGE);
        $this->assertSame(10, $second['total']);
        $this->assertCount(10 - auditreader::SECTIONS_PER_PAGE, $second['sections']);
        // The two pages must be disjoint, or paging is showing the same rows.
        $this->assertEmpty(array_intersect(
            array_column($first['sections'], 'id'),
            array_column($second['sections'], 'id')
        ));
    }

    /**
     * A group longer than one card opens with a PREVIEW, reports the full
     * count, and serves the remainder a full WINDOW at a time.
     *
     * The two constants are deliberately different sizes and this pins both:
     * a section card is a third of the page wide, so it opens with a handful,
     * but the first "show more" pulls a whole window rather than another
     * handful. Asserting the numbers rather than the constants would pass with
     * the two collapsed back together.
     */
    public function test_member_window_reports_the_full_total(): void {
        $this->resetAfterTest();
        $this->assertLessThan(
            auditreader::MEMBERS_PER_PAGE,
            auditreader::MEMBERS_PREVIEW,
            'A card is meant to open with less than a full window.'
        );
        $total = auditreader::MEMBERS_PREVIEW + auditreader::MEMBERS_PER_PAGE + 5;
        [, $context, $run] = $this->seed(['Turma unica'], $this->users($total));
        $reader = new auditreader($run, $context);

        $sections = $reader->get_sections('', '', 0, auditreader::SECTIONS_PER_PAGE);
        $section = $sections['sections'][0];
        $this->assertSame($total, $section['membertotal']);
        $this->assertCount(auditreader::MEMBERS_PREVIEW, $section['members']);
        $this->assertTrue($section['hasmore']);

        // What "show more" asks for: a full window from the preview's end.
        $more = $reader->get_members(
            $section['id'],
            '',
            auditreader::MEMBERS_PREVIEW,
            auditreader::MEMBERS_PER_PAGE
        );
        $this->assertSame($total, $more['total']);
        $this->assertCount(auditreader::MEMBERS_PER_PAGE, $more['members']);

        $rest = $reader->get_members(
            $section['id'],
            '',
            auditreader::MEMBERS_PREVIEW + auditreader::MEMBERS_PER_PAGE,
            auditreader::MEMBERS_PER_PAGE
        );
        $this->assertCount(5, $rest['members']);
    }

    /**
     * The outcome badge marks only the outcomes worth painting.
     *
     * "written" is what every row says on a run that worked, so the report
     * suppresses it; the four that need attention stay.
     */
    public function test_only_exceptional_outcomes_are_notable(): void {
        $this->assertFalse(auditreader::outcome_badge(runlog::WRITE_WRITTEN)['notable']);
        $exceptional = [
            runlog::WRITE_FAILED,
            runlog::WRITE_UNASSIGNED,
            runlog::WRITE_SKIPPED,
            runlog::WRITE_PLANNED,
        ];
        foreach ($exceptional as $status) {
            $this->assertTrue(auditreader::outcome_badge($status)['notable'], "status $status");
        }
    }

    /**
     * The keep-together count is a fact about the whole run, not about the
     * window it is read through.
     *
     * The control is the window itself: the second window holds 5 rows, so a
     * page-local computation would claim 4 and this test would fail.
     */
    public function test_together_count_is_runwide_not_windowwide(): void {
        $this->resetAfterTest();
        $total = auditreader::MEMBERS_PER_PAGE + 5;
        [, $context, $run] = $this->seed(
            ['Turma unica'],
            $this->users($total, 'Recife'),
            [['source' => 'city', 'mode' => options::AFFINITY_TOGETHER]]
        );
        $reader = new auditreader($run, $context);
        $sections = $reader->get_sections('', '', 0, auditreader::SECTIONS_PER_PAGE);
        $groupid = $sections['sections'][0]['id'];

        $window = $reader->get_members($groupid, '', auditreader::MEMBERS_PER_PAGE, auditreader::MEMBERS_PER_PAGE);
        $this->assertCount(5, $window['members'], 'The window must be smaller than the run for this to prove anything');

        $expected = get_string('auditwhytogether', 'local_groupdist', (object) [
            'index' => 1,
            'label' => get_string('city'),
            'value' => 'Recife',
            'count' => $total - 1,
        ]);
        $texts = array_column($window['members'][0]['why'], 'text');
        $this->assertContains($expected, $texts);
    }

    /**
     * The participant search narrows both the sections listed and the members
     * inside them, and leaves nothing behind when nothing matches.
     */
    public function test_participant_search(): void {
        $this->resetAfterTest();
        $users = $this->users(6, 'Recife', 'Comum');
        $users[] = ['Singularissimo', 'Recife'];
        [, $context, $run] = $this->seed(['Turma A', 'Turma B'], $users);
        $reader = new auditreader($run, $context);

        $all = $reader->get_sections('', '', 0, auditreader::SECTIONS_PER_PAGE);
        $this->assertSame(2, $all['total']);
        $this->assertSame(7, $all['matchingmembers']);

        $found = $reader->get_sections('', 'Singularissimo', 0, auditreader::SECTIONS_PER_PAGE);
        $this->assertSame(1, $found['total'], 'Only the section holding the match may survive');
        $this->assertSame(1, $found['matchingmembers']);
        $this->assertCount(1, $found['sections'][0]['members']);
        $this->assertStringContainsString('Singularissimo', $found['sections'][0]['members'][0]['name']);

        $none = $reader->get_sections('', 'Ninguemcomesse', 0, auditreader::SECTIONS_PER_PAGE);
        $this->assertSame(0, $none['total']);
        $this->assertSame([], $none['sections']);
    }

    /**
     * The group search matches the name as it was stored, including a name
     * that format_string() would entity-encode on the way to the screen.
     */
    public function test_group_search_matches_the_stored_name(): void {
        $this->resetAfterTest();
        [, $context, $run] = $this->seed(['R&D', 'Turma B'], $this->users(4));
        $reader = new auditreader($run, $context);

        $match = $reader->get_sections('r&d', '', 0, auditreader::SECTIONS_PER_PAGE);
        $this->assertSame(1, $match['total']);

        $other = $reader->get_sections('turma', '', 0, auditreader::SECTIONS_PER_PAGE);
        $this->assertSame(1, $other['total']);

        $nothing = $reader->get_sections('zzz', '', 0, auditreader::SECTIONS_PER_PAGE);
        $this->assertSame(0, $nothing['total']);
        $this->assertSame(0, $nothing['matchingmembers']);

        /* The participant count describes the sections being listed, not the
           run: the control is the unfiltered read, which must report more. */
        $everything = $reader->get_sections('', '', 0, auditreader::SECTIONS_PER_PAGE);
        $this->assertSame(4, $everything['matchingmembers']);
        $this->assertLessThan($everything['matchingmembers'], $match['matchingmembers']);
        $this->assertGreaterThan(0, $match['matchingmembers']);
    }

    /**
     * A keep-apart line never names the participant it explains — including in
     * the no-group bucket, where "different group" and "my own group" are the
     * same bucket and self-exclusion is the only thing separating them.
     *
     * The control is the peer: the same line must name the other unassigned
     * participant, so a test that simply found no names would fail.
     */
    public function test_apart_line_excludes_the_participant_itself(): void {
        global $DB;
        $this->resetAfterTest();

        // No groups at all: every candidate lands in the no-group bucket.
        [, $context, $run] = $this->seed(
            [],
            [['Primeiro', 'Recife'], ['Segundo', 'Recife']],
            [['source' => 'city', 'mode' => options::AFFINITY_APART]]
        );
        $rows = $DB->get_records('local_groupdist_run_user', ['runid' => $run->id]);
        $this->assertCount(2, $rows, 'Both candidates must be recorded for this to prove anything');
        foreach ($rows as $row) {
            $this->assertSame(0, (int) $row->groupid);
        }

        $reader = new auditreader($run, $context);
        $window = $reader->get_members(0, '', 0, auditreader::MEMBERS_PER_PAGE);
        $this->assertCount(2, $window['members']);

        foreach ($window['members'] as $member) {
            $lines = implode(' ', array_column($member['why'], 'text'));
            $this->assertStringNotContainsString(
                $member['name'],
                $lines,
                'A participant must never be listed among their own keep-apart peers'
            );
        }
        $first = $window['members'][0];
        $second = $window['members'][1];
        $this->assertStringContainsString(
            $second['name'],
            implode(' ', array_column($first['why'], 'text')),
            'The peer must still be named, or the assertion above passes vacuously'
        );
    }

    /**
     * Every participant with an account still there gets a profile link;
     * a pseudonymised row gets none and renders as removed.
     */
    public function test_profile_links(): void {
        $this->resetAfterTest();
        [$course, $context, $run] = $this->seed(['Turma A'], $this->users(3));
        $reader = new auditreader($run, $context);

        $section = $reader->get_sections('', '', 0, auditreader::SECTIONS_PER_PAGE)['sections'][0];
        foreach ($section['members'] as $member) {
            $this->assertFalse($member['removed']);
            $this->assertStringContainsString('/user/view.php', $member['profileurl']);
            $this->assertStringContainsString('course=' . $course->id, $member['profileurl']);
        }

        global $DB;
        $row = $DB->get_record('local_groupdist_run_user', ['runid' => $run->id], '*', IGNORE_MULTIPLE);
        runlog::pseudonymise_user((int) $row->userid);

        $after = (new auditreader($run, $context))
            ->get_sections('', '', 0, auditreader::SECTIONS_PER_PAGE)['sections'][0];
        $removed = array_values(array_filter($after['members'], function (array $member): bool {
            return $member['removed'];
        }));
        $this->assertCount(1, $removed);
        $this->assertSame('', $removed[0]['profileurl']);
        $this->assertSame(get_string('auditremoved', 'local_groupdist'), $removed[0]['name']);
    }

    /**
     * A group that still exists is never reported as deleted merely because
     * the person reading the log cannot see it.
     *
     * groups_get_all_groups() is visibility-filtered, so a live NONE-visibility
     * group is absent from it for any reader without
     * moodle/course:viewhiddengroups — and the marker would then assert, in a
     * permanent record, that the run wrote into a group that had been deleted.
     */
    public function test_hidden_group_is_not_reported_deleted(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $group = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'Turma reservada',
            'visibility' => GROUPS_VISIBILITY_NONE,
        ]);
        for ($i = 0; $i < 3; $i++) {
            $generator->create_and_enrol($course);
        }

        $this->setAdminUser();
        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $group->id],
            'roleid' => (int) current(get_archetype_roles('student'))->id,
            'seed' => 42,
        ]);
        $runid = runlog::create(distribution::build($options, $context), (int) get_admin()->id, $context);
        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);

        // Someone entitled to read the log, but not to see hidden groups.
        $auditor = $generator->create_and_enrol($course, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/course:viewhiddengroups', CAP_PREVENT, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($auditor);

        /* Preconditions. The reader may open the report, the group is live, and
           the filtered helper the marker used to ask denies it — without that
           last one this test would pass against the bug it exists for. */
        $this->assertTrue(has_capability('local/groupdist:viewauditlog', $context));
        $this->assertFalse(has_capability('moodle/course:viewhiddengroups', $context));
        $this->assertTrue($DB->record_exists('groups', ['id' => $group->id]));
        $this->assertArrayNotHasKey((int) $group->id, groups_get_all_groups($course->id));

        $sections = (new auditreader($run, $context))
            ->get_sections('', '', 0, auditreader::SECTIONS_PER_PAGE)['sections'];
        $this->assertCount(1, $sections);
        $this->assertSame((int) $group->id, $sections[0]['id']);
        $this->assertFalse($sections[0]['deleted']);

        /* Control: the marker still has to fire for a group that really is
           gone, or asserting false above would prove nothing. */
        groups_delete_group($group->id);
        $after = (new auditreader($run, $context))
            ->get_sections('', '', 0, auditreader::SECTIONS_PER_PAGE)['sections'];
        $this->assertTrue($after[0]['deleted']);
    }
    /**
     * A stored group rule renders its snapshot label, never the raw '1'.
     *
     * The reader keys this on the rule's SOURCE KIND rather than on the value,
     * so any stored value on a membership rule resolves to the label frozen at
     * apply time — which is what makes the log a snapshot and not a live
     * lookup. The control below proves the same run does render a real value
     * for a non-membership rule, so a failure above is the membership arm and
     * not an empty why-list.
     */
    public function test_group_rule_renders_its_snapshot_label(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $context = \core\context\course::instance($course->id);
        $destination = $generator->create_group(['courseid' => $course->id, 'name' => 'Turma 01']);
        $source = $generator->create_group(['courseid' => $course->id, 'name' => 'Lab team 2026.1']);

        foreach (['Alpha', 'Beta', 'Gamma'] as $lastname) {
            $user = $generator->create_and_enrol($course);
            $user->lastname = $lastname;
            $user->city = 'Recife';
            user_update_user($user, false);
            $generator->create_group_member(['groupid' => $source->id, 'userid' => $user->id]);
        }

        $this->setAdminUser();
        $options = options::from_array([
            'courseid' => $course->id,
            'groupids' => [(int) $destination->id],
            'affinityrules' => [
                ['source' => 'group_' . $source->id, 'mode' => options::AFFINITY_TOGETHER],
                ['source' => 'city', 'mode' => options::AFFINITY_TOGETHER],
            ],
            'roleid' => (int) current(get_archetype_roles('student'))->id,
            'seed' => 42,
        ]);
        $runid = runlog::create(distribution::build($options, $context), (int) get_admin()->id, $context);
        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);

        $reader = new auditreader($run, $context);
        $sections = $reader->get_sections('', '', 0, auditreader::SECTIONS_PER_PAGE);
        $lines = [];
        foreach ($sections['sections'] as $section) {
            foreach ($section['members'] as $member) {
                foreach ($member['why'] as $why) {
                    $lines[] = $why['text'];
                }
            }
        }
        $this->assertNotEmpty($lines, 'No "why here?" lines were built at all.');

        $joined = implode(' | ', $lines);
        $this->assertStringContainsString('Lab team 2026.1', $joined);
        $this->assertStringNotContainsString('"1"', $joined, 'The raw membership flag reached the audit output.');
        // Control: a plain field rule still shows its real value in the same run.
        $this->assertStringContainsString('Recife', $joined);
    }
}
