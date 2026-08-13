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
 * Admin settings.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_groupdist', new lang_string('pluginname', 'local_groupdist'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_groupdist/cleanupfieldsonuninstall',
        new lang_string('cleanupfieldsonuninstall', 'local_groupdist'),
        new lang_string('cleanupfieldsonuninstall_desc', 'local_groupdist'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_groupdist/maxaffinityrules',
        new lang_string('maxaffinityrules', 'local_groupdist'),
        new lang_string('maxaffinityrules_desc', 'local_groupdist'),
        \local_groupdist\local\ruleset::DEFAULT_MAX_RULES,
        PARAM_INT
    ));
}
