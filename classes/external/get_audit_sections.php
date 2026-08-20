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

namespace local_groupdist\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_groupdist\local\auditreader;
use local_groupdist\output\audit_detail;

/**
 * One page of group sections of an applied distribution run.
 *
 * Serves the search box and the "load more groups" control of the audit
 * report. Nothing is computed here: the payload is the stored snapshot,
 * windowed, and it comes out of the same reader the server-rendered first
 * page uses.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_audit_sections extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters The parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'runid' => new external_value(PARAM_INT, 'Audit run id'),
            'courseid' => new external_value(PARAM_INT, 'Course the run belongs to'),
            'userquery' => new external_value(PARAM_TEXT, 'Participant name search', VALUE_DEFAULT, ''),
            'groupquery' => new external_value(PARAM_TEXT, 'Group name search', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Zero-based page of sections', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return one page of sections.
     *
     * @param int $runid Audit run id.
     * @param int $courseid Course the run belongs to.
     * @param string $userquery Participant name search.
     * @param string $groupquery Group name search.
     * @param int $page Zero-based page of sections.
     * @return array The payload.
     */
    public static function execute(
        int $runid,
        int $courseid,
        string $userquery,
        string $groupquery,
        int $page
    ): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'runid' => $runid,
            'courseid' => $courseid,
            'userquery' => $userquery,
            'groupquery' => $groupquery,
            'page' => $page,
        ]);

        [$run, $context] = audit_ws::resolve_run($params['runid'], $params['courseid']);
        $reader = new auditreader($run, $context);

        $perpage = auditreader::SECTIONS_PER_PAGE;
        $data = $reader->get_sections(
            audit_detail::clean_query($params['groupquery']),
            audit_detail::clean_query($params['userquery']),
            max(0, $params['page']),
            $perpage
        );

        $sections = [];
        foreach ($data['sections'] as $section) {
            $section['remaining'] = max(0, (int) $section['membertotal'] - (int) $section['shown']);
            $section['hasseats'] = ($section['seats'] !== null);
            $section['seats'] = (int) ($section['seats'] ?? 0);
            $section['canpin'] = true;
            $section['moreurl'] = (new \moodle_url('/local/groupdist/audit.php', [
                'id' => (int) $run->courseid,
                'run' => (int) $run->id,
                'group' => (int) $section['id'],
            ]))->out(false);
            $sections[] = $section;
        }

        // The bar is re-rendered rather than patched client-side: it is core's
        // own renderable, and a search changes the total it describes.
        $barurl = new \moodle_url('/local/groupdist/audit.php', [
            'id' => (int) $run->courseid,
            'run' => (int) $run->id,
        ]);
        foreach (['uq' => $params['userquery'], 'gq' => $params['groupquery']] as $key => $value) {
            if (trim($value) !== '') {
                $barurl->param($key, $value);
            }
        }

        return [
            'sections' => $sections,
            'total' => $data['total'],
            'matchingmembers' => $data['matchingmembers'],
            'shown' => min($data['total'], (max(0, $params['page']) + 1) * $perpage),
            'pagingbar' => $PAGE->get_renderer('core')->render(
                new \core\output\paging_bar($data['total'], max(0, $params['page']), $perpage, $barurl)
            ),
        ];
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure The structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sections' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Snapshot group id (0 = without a group)'),
                'name' => new external_value(PARAM_TEXT, 'Group name as of apply time'),
                'nogroup' => new external_value(PARAM_BOOL, 'Whether this is the no-group bucket'),
                'deleted' => new external_value(PARAM_BOOL, 'Whether the group is gone from the course'),
                'seats' => new external_value(PARAM_INT, 'Declared seats (0 when none)'),
                'hasseats' => new external_value(PARAM_BOOL, 'Whether seats were declared'),
                'membertotal' => new external_value(PARAM_INT, 'Participants in this section'),
                'shown' => new external_value(PARAM_INT, 'Participants carried in this payload'),
                'hasmore' => new external_value(PARAM_BOOL, 'Whether the section holds more'),
                'remaining' => new external_value(PARAM_INT, 'Participants not carried'),
                'canpin' => new external_value(PARAM_BOOL, 'Whether the section links to its own page'),
                'moreurl' => new external_value(PARAM_URL, 'URL of the section on its own page'),
                'members' => new external_multiple_structure(audit_ws::member_structure()),
            ])),
            'total' => new external_value(PARAM_INT, 'Sections matching the search'),
            'matchingmembers' => new external_value(PARAM_INT, 'Participants matching the search'),
            'shown' => new external_value(PARAM_INT, 'Sections listed up to and including this page'),
            'pagingbar' => new external_value(PARAM_RAW, 'Server-rendered paging bar for the current result set'),
        ]);
    }
}
