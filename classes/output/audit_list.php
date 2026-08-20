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

use local_groupdist\local\runlog;

/**
 * Audit log run list for one course.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_list implements \renderable, \templatable {
    /** @var int Runs per page. */
    public const PER_PAGE = 20;

    /** @var int The course id. */
    protected int $courseid;

    /** @var int Zero-based page. */
    protected int $page;

    /**
     * Constructor.
     *
     * @param int $courseid The course id.
     * @param int $page Zero-based page number.
     */
    public function __construct(int $courseid, int $page) {
        $this->courseid = $courseid;
        $this->page = $page;
    }

    /**
     * Total number of runs (for the paging bar).
     *
     * @return int The count.
     */
    public function get_total(): int {
        global $DB;
        return $DB->count_records('local_groupdist_run', ['courseid' => $this->courseid]);
    }

    /**
     * Export the template context.
     *
     * @param \renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(\renderer_base $output): array {
        global $DB;

        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $records = $DB->get_records_sql(
            "SELECT r.*, u.deleted AS userdeleted, {$namefields}
               FROM {local_groupdist_run} r
          LEFT JOIN {user} u ON u.id = r.userid
              WHERE r.courseid = :courseid
           ORDER BY r.timecreated DESC, r.id DESC",
            ['courseid' => $this->courseid],
            $this->page * self::PER_PAGE,
            self::PER_PAGE
        );

        $rows = [];
        foreach ($records as $record) {
            $rules = json_decode($record->rulesjson, true)['rules'] ?? [];
            $chips = [];
            foreach (array_values($rules) as $i => $rule) {
                $chips[] = ['index' => $i + 1, 'apart' => ($rule['mode'] ?? '') === 'apart'];
            }
            // A pseudonymised run has userid 0; an account since removed leaves
            // the join empty. Neither has a profile page to link to.
            $applied = ((int) $record->userid > 0 && $record->userdeleted !== null);
            $rows[] = [
                'when' => userdate((int) $record->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                'byname' => $applied ? fullname($record) : get_string('auditremoved', 'local_groupdist'),
                'byprofileurl' => ($applied && empty($record->userdeleted))
                    ? \core_user::get_profile_url(
                        (object) ['id' => (int) $record->userid],
                        \core\context\course::instance($this->courseid)
                    )->out(false)
                    : '',
                'rules' => $chips,
                'rulecount' => count($chips),
                'written' => (int) $record->memberswritten,
                'total' => (int) $record->memberstotal,
                'status' => self::status_badge((int) $record->status),
                'restored' => (bool) $record->restored,
                'detailurl' => (new \moodle_url('/local/groupdist/audit.php', [
                    'id' => $this->courseid,
                    'run' => (int) $record->id,
                ]))->out(false),
            ];
        }

        return ['runs' => $rows, 'hasruns' => (bool) $rows];
    }

    /**
     * Localised status badge data.
     *
     * @param int $status A runlog STATUS_* value.
     * @return array Keys 'label' and 'class' (Bootstrap suffix).
     */
    public static function status_badge(int $status): array {
        switch ($status) {
            case runlog::STATUS_COMPLETED:
                return ['label' => get_string('auditstatuscompleted', 'local_groupdist'), 'class' => 'success'];
            case runlog::STATUS_PARTIAL:
                return ['label' => get_string('auditstatuspartial', 'local_groupdist'), 'class' => 'warning'];
            case runlog::STATUS_ABORTED:
                return ['label' => get_string('auditstatusaborted', 'local_groupdist'), 'class' => 'danger'];
            default:
                return ['label' => get_string('auditstatuspending', 'local_groupdist'), 'class' => 'secondary'];
        }
    }
}
