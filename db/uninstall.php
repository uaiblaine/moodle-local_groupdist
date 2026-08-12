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
 * Uninstall hook.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Clean up the provisioned group custom fields when the admin opted in.
 *
 * Removing the fields cascades their values, so this is gated behind the
 * cleanupfieldsonuninstall setting (default off) — mirroring the pattern of
 * availability_competency's cleanuponcompetencydeletion. Plugin config still
 * exists at this point: core deletes it after this hook runs.
 *
 * @return bool Always true.
 */
function xmldb_local_groupdist_uninstall(): bool {
    if (get_config('local_groupdist', 'cleanupfieldsonuninstall')) {
        \local_groupdist\local\fields::delete_provisioned_fields();
    }
    return true;
}
