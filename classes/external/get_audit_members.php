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
 * One window of participants inside a single section of an audit run.
 *
 * Serves the per-group "show more" control, so a group holding thousands of
 * participants is walked a window at a time instead of arriving whole.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_audit_members extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters The parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'runid' => new external_value(PARAM_INT, 'Audit run id'),
            'courseid' => new external_value(PARAM_INT, 'Course the run belongs to'),
            'groupid' => new external_value(PARAM_INT, 'Snapshot group id (0 = without a group)'),
            'userquery' => new external_value(PARAM_TEXT, 'Participant name search', VALUE_DEFAULT, ''),
            'limitfrom' => new external_value(PARAM_INT, 'Window offset', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return one window of participants.
     *
     * @param int $runid Audit run id.
     * @param int $courseid Course the run belongs to.
     * @param int $groupid Snapshot group id.
     * @param string $userquery Participant name search.
     * @param int $limitfrom Window offset.
     * @return array The payload.
     */
    public static function execute(
        int $runid,
        int $courseid,
        int $groupid,
        string $userquery,
        int $limitfrom
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'runid' => $runid,
            'courseid' => $courseid,
            'groupid' => $groupid,
            'userquery' => $userquery,
            'limitfrom' => $limitfrom,
        ]);

        [$run, $context] = audit_ws::resolve_run($params['runid'], $params['courseid']);
        $reader = new auditreader($run, $context);
        if (!$reader->has_section($params['groupid'])) {
            throw new \invalid_parameter_exception('groupid');
        }

        $limitfrom = max(0, $params['limitfrom']);
        $data = $reader->get_members(
            $params['groupid'],
            audit_detail::clean_query($params['userquery']),
            $limitfrom,
            auditreader::MEMBERS_PER_PAGE
        );

        return [
            'members' => $data['members'],
            'total' => $data['total'],
            'shown' => $limitfrom + count($data['members']),
        ];
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure The structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'members' => new external_multiple_structure(audit_ws::member_structure()),
            'total' => new external_value(PARAM_INT, 'Participants in this section'),
            'shown' => new external_value(PARAM_INT, 'Participants listed up to and including this window'),
        ]);
    }
}
