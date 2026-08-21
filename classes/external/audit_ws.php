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

use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

/**
 * Shared plumbing for the two audit report web services.
 *
 * Both services window the same snapshot, so they share one security preamble
 * and one member return structure. Keeping the structure in a single place is
 * what stops the allowlist of the two services drifting apart —
 * clean_returnvalue() strips undeclared keys silently, so a field added to the
 * member payload has to be added here once rather than in two lists.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_ws {
    /**
     * Load a run and authorise the caller for it.
     *
     * The context is derived server-side from the course id, never from a raw
     * context id, and the run has to belong to that course: a run id from
     * another course must not leak across contexts, exactly as the audit page
     * itself enforces.
     *
     * @param int $runid The run id.
     * @param int $courseid The course the caller believes the run belongs to.
     * @return array [$run, $context].
     * @throws \moodle_exception When the run does not belong to the course.
     * @throws \required_capability_exception When the caller may not read it.
     */
    public static function resolve_run(int $runid, int $courseid): array {
        global $DB;

        /* Authorise before reading anything. The course id is the caller's
           claim, so it is what the context is derived from and what the run
           has to agree with: loading the run first would answer "does this
           run id exist, and in which course" to a caller who has not yet
           passed require_login, which is a site-wide run enumerator. */
        $context = \core\context\course::instance($courseid, MUST_EXIST);
        \core_external\external_api::validate_context($context);
        if (isguestuser()) {
            throw new \moodle_exception('noguest');
        }
        require_capability('local/groupdist:viewauditlog', $context);

        $run = $DB->get_record('local_groupdist_run', ['id' => $runid], '*', MUST_EXIST);
        if ((int) $run->courseid !== $courseid) {
            // A run id belonging to another course must not leak across contexts.
            throw new \moodle_exception('invaliddata', 'error');
        }

        return [$run, $context];
    }

    /**
     * The return structure of one participant row.
     *
     * @return external_single_structure The structure.
     */
    public static function member_structure(): external_single_structure {
        return new external_single_structure([
            'name' => new external_value(PARAM_TEXT, 'Participant name, or the removed marker'),
            'removed' => new external_value(PARAM_BOOL, 'Whether the participant is pseudonymised or gone'),
            'profileurl' => new external_value(PARAM_URL, 'Profile URL ("" when there is none to reach)'),
            'groupname' => new external_value(PARAM_TEXT, 'Snapshot name of the group they landed in'),
            'outcome' => new external_single_structure([
                'label' => new external_value(PARAM_TEXT, 'Localised write outcome'),
                'notable' => new external_value(PARAM_BOOL, 'Whether the outcome is worth painting'),
                'class' => new external_value(PARAM_ALPHA, 'Bootstrap badge suffix'),
            ]),
            'why' => new external_multiple_structure(new external_single_structure([
                'text' => new external_value(PARAM_TEXT, 'One explanation line'),
                'muted' => new external_value(PARAM_BOOL, 'Whether the line renders muted'),
                'warn' => new external_value(PARAM_BOOL, 'Whether the line reports a violation'),
            ]), 'Explanations derived from the stored snapshot'),
        ]);
    }
}
