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
 * Procedural callbacks.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * User preferences this plugin may set through the web service.
 *
 * @return array Preference definitions keyed by name.
 */
function local_groupdist_user_preferences(): array {
    return [
        // Comma-separated column keys the user collapsed on the bulk edit table.
        'local_groupdist_bulkedit_hiddencols' => [
            'type' => PARAM_NOTAGS,
            'null' => NULL_NOT_ALLOWED,
            'default' => '',
            'permissioncallback' => function ($user, $preferencename) {
                global $USER;
                return $user->id == $USER->id;
            },
        ],
    ];
}
