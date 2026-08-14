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
 * Upgrade steps.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool Always true.
 */
function xmldb_local_groupdist_upgrade(int $oldversion): bool {
    if ($oldversion < 2026081201) {
        // The applyresult message provider (db/messages.php) installs here.
        upgrade_plugin_savepoint(true, 2026081201, 'local', 'groupdist');
    }

    if ($oldversion < 2026081210) {
        // The bulk edit feature: save_group_fields web service registers here.
        upgrade_plugin_savepoint(true, 2026081210, 'local', 'groupdist');
    }

    if ($oldversion < 2026081305) {
        // Audit log: one snapshot per applied run plus per-participant rows.
        global $DB;
        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_groupdist_run');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('seed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('fingerprint', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('pluginversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('restored', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('optionsjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('rulesjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('groupsjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('warningsjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('memberstotal', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('memberswritten', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_groupdist_run_user');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('runid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('valuesjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('writestatus', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('runid', XMLDB_KEY_FOREIGN, ['runid'], 'local_groupdist_run', ['id']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026081305, 'local', 'groupdist');
    }

    // Catch-all: re-provision the group custom fields on every upgrade, so an
    // upgrade heals a category an admin deleted between releases.
    \local_groupdist\local\fields::ensure_fields_exist();
    return true;
}
