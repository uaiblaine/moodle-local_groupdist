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
 * Course backup support for the distribution audit log.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the distribution audit log to course backups.
 *
 * The audit rows are personal data (who applied a run and each participant's
 * rule values at apply time), so they follow core's course log handling: they
 * travel only when the "Include course logs" root setting is on AND the
 * backup carries user data AND the backup is not anonymised.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_groupdist_plugin extends backup_local_plugin {
    /**
     * Attach the audit log structure to the course element.
     *
     * @return backup_plugin_element The plugin element (left empty when the
     *   audit is excluded by the settings gate).
     */
    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element();

        /* Settings gate, mirroring core's course log handling. The three
           root settings exist in every plan mode, so the lookups are safe;
           in import mode "users" defaults to off, which keeps the audit out
           of course imports by the same rule. */
        if (
            !$this->get_setting_value('logs')
            || !$this->get_setting_value('users')
            || $this->get_setting_value('anonymize')
        ) {
            return $plugin;
        }

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $runs = new backup_nested_element('runs');
        $run = new backup_nested_element('run', ['id'], [
            'userid',
            'status',
            'seed',
            'fingerprint',
            'pluginversion',
            'restored',
            'optionsjson',
            'rulesjson',
            'groupsjson',
            'warningsjson',
            'memberstotal',
            'memberswritten',
            'timecreated',
            'timecompleted',
        ]);
        $runusers = new backup_nested_element('runusers');
        $runuser = new backup_nested_element('runuser', ['id'], [
            'userid',
            'valuesjson',
            'groupid',
            'writestatus',
        ]);

        $pluginwrapper->add_child($runs);
        $runs->add_child($run);
        $run->add_child($runusers);
        $runusers->add_child($runuser);

        $run->set_source_table('local_groupdist_run', ['courseid' => backup::VAR_COURSEID], 'id');
        $runuser->set_source_table('local_groupdist_run_user', ['runid' => backup::VAR_PARENTID], 'id');

        /* The applier is often not enrolled and a participant may have been
           unenrolled since the run: annotating the ids includes those users
           in the backup, so the restore can remap instead of pseudonymise.
           Pseudonymised rows (userid 0) are skipped by the annotation. */
        $run->annotate_ids('user', 'userid');
        $runuser->annotate_ids('user', 'userid');

        return $plugin;
    }
}
