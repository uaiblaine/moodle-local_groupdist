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
 * Add the distribution audit log to the course Reports section.
 *
 * The 'coursereports' settings-navigation container is exactly what the
 * course Reports page (report/view.php) lists, and it also feeds the
 * secondary navigation's Reports menu.
 *
 * @param settings_navigation $settingsnav The settings navigation.
 * @param \core\context|null $context The current context.
 * @return void
 */
function local_groupdist_extend_settings_navigation(settings_navigation $settingsnav, ?\core\context $context): void {
    if (!$context instanceof \core\context\course || $context->instanceid == SITEID) {
        return;
    }
    if (!has_capability('local/groupdist:viewauditlog', $context)) {
        return;
    }
    $reports = $settingsnav->find('coursereports', \navigation_node::TYPE_CONTAINER);
    if (!$reports) {
        return;
    }
    $reports->add(
        get_string('auditlog', 'local_groupdist'),
        new moodle_url('/local/groupdist/audit.php', ['id' => $context->instanceid]),
        \navigation_node::TYPE_SETTING,
        null,
        'localgroupdistaudit'
    );
}

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
