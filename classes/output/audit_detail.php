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

use local_groupdist\local\auditreader;

/**
 * Audit run detail: the snapshot rendered one page at a time.
 *
 * The header (rules as of apply time, warnings, counts) is a function of the
 * run row alone. Everything below it — group sections and their participants —
 * is paged: a run can hold thousands of participants across hundreds of
 * groups, so this class asks \local_groupdist\local\auditreader for one window
 * and renders that. The same windows are served to the page's AMD module by
 * the two audit web services, so the server-rendered first page and every
 * lazily loaded page come out of one code path.
 *
 * Everything shown comes from the stored snapshot, never from the live tables
 * and never by replaying the engine. Two reader-side overlays apply at render
 * time only: rule values the VIEWER may not see are masked (the snapshot stays
 * intact), and pseudonymised rows appear as removed participants.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_detail implements \renderable, \templatable {
    /** @var int The "no group is pinned" sentinel — 0 is the no-group bucket. */
    public const GROUP_ANY = -1;

    /** @var int Longest search text accepted from the URL. */
    public const SEARCH_MAX = 100;

    /** @var \stdClass The run record. */
    protected \stdClass $run;

    /** @var \core\context\course The course context (viewer-side checks). */
    protected \core\context\course $context;

    /** @var auditreader The paged snapshot reader. */
    protected auditreader $reader;

    /** @var string Participant name search. */
    protected string $userquery;

    /** @var string Group name search. */
    protected string $groupquery;

    /** @var int Zero-based page of group sections. */
    protected int $page;

    /** @var int Pinned group id, or GROUP_ANY. */
    protected int $groupid;

    /** @var int Zero-based page of members inside the pinned group. */
    protected int $memberpage;

    /**
     * Constructor.
     *
     * @param \stdClass $run The run record.
     * @param \core\context\course $context The course context.
     * @param array $params Optional keys: userquery, groupquery, page, group,
     *   memberpage. 'group' defaults to GROUP_ANY because 0 is a real section.
     */
    public function __construct(\stdClass $run, \core\context\course $context, array $params = []) {
        $this->run = $run;
        $this->context = $context;
        $this->reader = new auditreader($run, $context);
        $this->userquery = self::clean_query((string) ($params['userquery'] ?? ''));
        $this->groupquery = self::clean_query((string) ($params['groupquery'] ?? ''));
        $this->page = max(0, (int) ($params['page'] ?? 0));
        $this->groupid = (int) ($params['group'] ?? self::GROUP_ANY);
        $this->memberpage = max(0, (int) ($params['memberpage'] ?? 0));
        if ($this->groupid !== self::GROUP_ANY && !$this->reader->has_section($this->groupid)) {
            // A bookmark naming a group this run never touched: fall back to
            // the section list rather than render a nameless empty card.
            $this->groupid = self::GROUP_ANY;
        }
    }

    /**
     * Trim a search box value to a sane length.
     *
     * @param string $query The raw query.
     * @return string The cleaned query.
     */
    public static function clean_query(string $query): string {
        return trim(\core_text::substr(trim($query), 0, self::SEARCH_MAX));
    }

    /**
     * Export the template context.
     *
     * @param \renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(\renderer_base $output): array {
        $run = $this->run;
        $pinned = ($this->groupid !== self::GROUP_ANY);

        $body = $pinned ? $this->export_pinned_group($output) : $this->export_sections($output);

        return $body + [
            'heading' => get_string('auditrun', 'local_groupdist', userdate(
                (int) $run->timecreated,
                get_string('strftimedatetimeshort', 'langconfig')
            )),
            'byname' => $this->applier_name(),
            'byprofileurl' => $this->applier_profile_url(),
            'meta' => get_string('auditmeta', 'local_groupdist', (object) [
                'seed' => (int) $run->seed,
                'version' => (string) $run->pluginversion,
                'total' => (int) $run->memberstotal,
                'written' => (int) $run->memberswritten,
            ]),
            'status' => audit_list::status_badge((int) $run->status),
            'restored' => (bool) $run->restored,
            'rules' => $this->export_rule_chips(),
            'hasrules' => (bool) $this->reader->get_rule_info(),
            'warnings' => $this->reader->get_warnings(),
            'pinned' => $pinned,
            'userquery' => $this->userquery,
            'groupquery' => $this->groupquery,
            'hasfilters' => ($this->userquery !== '' || $this->groupquery !== ''),
            // A GET form discards its action's query string, so the run and
            // course ids travel as hidden inputs, not in the action URL.
            'formaction' => (new \moodle_url('/local/groupdist/audit.php'))->out(false),
            'pinnedgroup' => $pinned ? $this->groupid : '',
            'courseid' => (int) $run->courseid,
            'runid' => (int) $run->id,
            'clearurl' => $this->detail_url()->out(false),
            'configjson' => json_encode([
                'runid' => (int) $run->id,
                'courseid' => (int) $run->courseid,
                'sectionsperpage' => auditreader::SECTIONS_PER_PAGE,
                'pinned' => $pinned ? $this->groupid : self::GROUP_ANY,
            ]),
            'backurl' => (new \moodle_url('/local/groupdist/audit.php', ['id' => (int) $run->courseid]))->out(false),
        ];
    }

    /**
     * The default body: one page of group sections.
     *
     * @param \renderer_base $output The renderer.
     * @return array The body part of the context.
     */
    protected function export_sections(\renderer_base $output): array {
        $perpage = auditreader::SECTIONS_PER_PAGE;
        $data = $this->reader->get_sections($this->groupquery, $this->userquery, $this->page, $perpage);

        $sections = [];
        foreach ($data['sections'] as $section) {
            $sections[] = $this->decorate_section($section);
        }

        $shown = min($data['total'], ($this->page + 1) * $perpage);
        return [
            'sections' => $sections,
            'hassections' => (bool) $sections,
            'sectiontotal' => $data['total'],
            'counter' => get_string('auditcountergroups', 'local_groupdist', (object) [
                'shown' => $shown,
                'total' => $data['total'],
                'members' => $data['matchingmembers'],
            ]),
            'pagingbar' => $output->render(new \core\output\paging_bar(
                $data['total'],
                $this->page,
                $perpage,
                $this->detail_url(['uq' => $this->userquery, 'gq' => $this->groupquery])
            )),
        ];
    }

    /**
     * The pinned-group body: one section, with its members paged.
     *
     * This is the path a reader without JavaScript takes to walk a group whose
     * membership is longer than one window.
     *
     * @param \renderer_base $output The renderer.
     * @return array The body part of the context.
     */
    protected function export_pinned_group(\renderer_base $output): array {
        $perpage = auditreader::MEMBERS_PER_PAGE;
        $data = $this->reader->get_members($this->groupid, $this->userquery, $this->memberpage * $perpage, $perpage);
        $section = $this->reader->get_section_header($this->groupid);
        $section['members'] = $data['members'];
        $section['membertotal'] = $data['total'];
        $section['shown'] = count($data['members']);

        $shown = min($data['total'], ($this->memberpage + 1) * $perpage);
        return [
            'sections' => [$this->decorate_section($section, false)],
            'hassections' => true,
            'sectiontotal' => 1,
            'counter' => get_string('auditcountermembers', 'local_groupdist', (object) [
                'shown' => $shown,
                'total' => $data['total'],
            ]),
            'pagingbar' => $output->render(new \core\output\paging_bar(
                $data['total'],
                $this->memberpage,
                $perpage,
                $this->detail_url(['uq' => $this->userquery, 'group' => $this->groupid]),
                'mpage'
            )),
        ];
    }

    /**
     * Add the presentation-only keys one section needs.
     *
     * @param array $section A section as returned by the reader.
     * @param bool $canpin Whether the section may link to its pinned view.
     * @return array The decorated section.
     */
    protected function decorate_section(array $section, bool $canpin = true): array {
        $section['remaining'] = max(0, (int) $section['membertotal'] - (int) $section['shown']);
        $section['moreurl'] = $canpin
            ? $this->detail_url(['group' => (int) $section['id'], 'uq' => $this->userquery])->out(false)
            : '';
        $section['canpin'] = $canpin;
        $section['hasseats'] = ($section['seats'] !== null);
        $section['seats'] = (int) ($section['seats'] ?? 0);
        return $section;
    }

    /**
     * The detail URL of this run, carrying the given extra parameters.
     *
     * @param array $extra Extra URL parameters; empty values are dropped.
     * @return \moodle_url The URL.
     */
    protected function detail_url(array $extra = []): \moodle_url {
        $params = ['id' => (int) $this->run->courseid, 'run' => (int) $this->run->id];
        foreach ($extra as $key => $value) {
            if ($value !== '' && $value !== null && $value !== self::GROUP_ANY) {
                $params[$key] = $value;
            }
        }
        return new \moodle_url('/local/groupdist/audit.php', $params);
    }

    /**
     * The rule chips shown under the header.
     *
     * @return array The chip contexts.
     */
    protected function export_rule_chips(): array {
        $chips = [];
        foreach ($this->reader->get_rule_info() as $info) {
            $chips[] = [
                'index' => $info['index'],
                'label' => $info['label'],
                'apart' => $info['apart'],
                'mode' => $info['apart']
                    ? get_string('modeapart', 'local_groupdist')
                    : get_string('modetogether', 'local_groupdist'),
                'masked' => $info['masked'],
            ];
        }
        return $chips;
    }

    /**
     * The applier's current name, or the removed marker.
     *
     * @return string The display name.
     */
    protected function applier_name(): string {
        $user = $this->applier_record();
        return $user ? fullname($user) : get_string('auditremoved', 'local_groupdist');
    }

    /**
     * The applier's profile URL, or '' when there is nobody to link to.
     *
     * @return string The URL or an empty string.
     */
    protected function applier_profile_url(): string {
        $user = $this->applier_record();
        if (!$user || !empty($user->deleted)) {
            return '';
        }
        return \core_user::get_profile_url((object) ['id' => (int) $user->id], $this->context)->out(false);
    }

    /**
     * The applier's user record, loaded once.
     *
     * @return \stdClass|null The record, or null when the account is gone.
     */
    protected function applier_record(): ?\stdClass {
        global $DB;
        static $cache = [];

        $userid = (int) $this->run->userid;
        if ($userid <= 0) {
            return null;
        }
        if (!array_key_exists($userid, $cache)) {
            $namefields = implode(',', \core_user\fields::for_name()->get_required_fields());
            $cache[$userid] = $DB->get_record('user', ['id' => $userid], 'id,deleted,' . $namefields) ?: null;
        }
        return $cache[$userid];
    }
}
