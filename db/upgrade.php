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

    // Catch-all: re-provision the group custom fields on every upgrade, so an
    // upgrade heals a category an admin deleted between releases.
    \local_groupdist\local\fields::ensure_fields_exist();
    return true;
}
