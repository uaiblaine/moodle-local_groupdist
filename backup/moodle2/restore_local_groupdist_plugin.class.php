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

/**
 * Course restore support for the distribution audit log.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the distribution audit log into the target course.
 *
 * The snapshot is recreated with its references remapped: the applier and
 * every participant map through the restored users (participants missing
 * from the backup become pseudonymised rows — userid zeroed, values
 * blanked), and group references — each participant's planned group and the
 * ids keying the groups snapshot — map through the restored groups, keeping
 * the historical id (rendered as a since-deleted group) when a group was not
 * carried over. Restored runs are flagged so the audit UI can mark them.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_groupdist_plugin extends restore_local_plugin {
    /**
     * Declare the audit log paths inside course.xml.
     *
     * @return restore_path_element[] The paths this plugin handles.
     */
    protected function define_course_plugin_structure() {
        return [
            new restore_path_element($this->get_namefor('run'), $this->get_pathfor('/runs/run')),
            new restore_path_element($this->get_namefor('runuser'), $this->get_pathfor('/runs/run/runusers/runuser')),
        ];
    }

    /**
     * Restore one run row.
     *
     * @param array $data The parsed run element.
     * @return void
     */
    public function process_local_groupdist_run($data) {
        global $DB;

        if (!$this->include_audit()) {
            return;
        }
        $data = (object) $data;
        $oldid = (int) $data->id;
        unset($data->id);

        $data->courseid = (int) $this->task->get_courseid();
        $data->userid = max(0, (int) $this->get_mappingid('user', $data->userid, 0));
        $data->restored = 1;
        $data->groupsjson = $this->remap_groupsjson((string) $data->groupsjson);

        $newid = $DB->insert_record('local_groupdist_run', $data);
        $this->set_mapping($this->get_namefor('run'), $oldid, $newid);
    }

    /**
     * Restore one participant row of a run.
     *
     * @param array $data The parsed runuser element.
     * @return void
     */
    public function process_local_groupdist_runuser($data) {
        global $DB;

        if (!$this->include_audit()) {
            return;
        }
        $data = (object) $data;
        unset($data->id);
        $data->runid = (int) $this->get_new_parentid($this->get_namefor('run'));

        $userid = (int) $this->get_mappingid('user', $data->userid, 0);
        if ($userid <= 0) {
            // Participant missing from the backup: keep the row pseudonymised.
            $userid = 0;
            $data->valuesjson = '';
        }
        $data->userid = $userid;

        $groupid = (int) $data->groupid;
        if ($groupid > 0) {
            /* Keep the historical id when the group was not carried over: the
               remapped groups snapshot keeps it too, so the audit UI still
               pairs the member with the snapshot entry and renders the group
               as since deleted. */
            $mapped = (int) $this->get_mappingid('group', $groupid, 0);
            $data->groupid = ($mapped > 0) ? $mapped : $groupid;
        }

        $DB->insert_record('local_groupdist_run_user', $data);
    }

    /**
     * Whether the restore should recreate the audit log.
     *
     * The restore-side "logs" root setting always exists (it defaults to off
     * and is locked when the backup carried no logs); the existence probe is
     * cheap insurance against exotic plans.
     *
     * @return bool True to restore the audit rows.
     */
    protected function include_audit(): bool {
        return $this->task->setting_exists('logs') && (bool) $this->get_setting_value('logs');
    }

    /**
     * Remap the ids keying the stored groups snapshot to the restored groups.
     *
     * Names, seats and counts stay exactly as recorded — only the id keys
     * move, so the audit UI keeps pairing members with snapshot entries and
     * detecting which groups still exist. Ids without a mapping stay
     * historical.
     *
     * @param string $groupsjson The stored snapshot JSON.
     * @return string The remapped snapshot JSON.
     */
    protected function remap_groupsjson(string $groupsjson): string {
        $groups = json_decode($groupsjson, true);
        if (!is_array($groups)) {
            return $groupsjson;
        }
        foreach ($groups as $i => $group) {
            $oldid = (int) ($group['id'] ?? 0);
            if ($oldid > 0) {
                $mapped = (int) $this->get_mappingid('group', $oldid, 0);
                if ($mapped > 0) {
                    $groups[$i]['id'] = $mapped;
                }
            }
        }
        return json_encode($groups);
    }
}
