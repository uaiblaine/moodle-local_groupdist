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

namespace local_groupdist\task;

/**
 * Scheduled retention sweep over the audit log.
 *
 * Deletes runs (and their per-participant rows) older than the
 * auditretentiondays setting; 0 keeps the log forever.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_audit extends \core\task\scheduled_task {
    /**
     * Localised task name.
     *
     * @return string The name.
     */
    public function get_name(): string {
        return get_string('task_cleanup_audit', 'local_groupdist');
    }

    /**
     * Delete expired runs.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $days = (int) get_config('local_groupdist', 'auditretentiondays');
        if ($days <= 0) {
            return;
        }
        $cutoff = time() - $days * DAYSECS;

        $runids = $DB->get_fieldset_select(
            'local_groupdist_run',
            'id',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );
        if (!$runids) {
            return;
        }
        foreach (array_chunk($runids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'r');
            $DB->delete_records_select('local_groupdist_run_user', "runid {$insql}", $params);
            $DB->delete_records_select('local_groupdist_run', "id {$insql}", $params);
        }
        mtrace('local_groupdist: retention sweep removed ' . count($runids) . ' audit run(s).');
    }
}
