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

namespace local_groupdist\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 *
 * The plugin persists no user data in tables of its own: distributions are
 * recomputed deterministically from a seed, group memberships live in core
 * tables, and the provisioned group custom fields (seats, location) describe
 * groups, not people. The only personal data is one user preference — the
 * columns collapsed on the bulk edit table.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Describe the stored personal data.
     *
     * @param collection $collection The metadata collection.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_user_preference(
            'local_groupdist_bulkedit_hiddencols',
            'privacy:metadata:preference:bulkeditcols'
        );
        return $collection;
    }

    /**
     * Export the user preference.
     *
     * @param int $userid The user id.
     * @return void
     */
    public static function export_user_preferences(int $userid): void {
        $value = get_user_preferences('local_groupdist_bulkedit_hiddencols', null, $userid);
        if ($value !== null) {
            writer::export_user_preference(
                'local_groupdist',
                'local_groupdist_bulkedit_hiddencols',
                $value,
                get_string('privacy:metadata:preference:bulkeditcols', 'local_groupdist')
            );
        }
    }
}
