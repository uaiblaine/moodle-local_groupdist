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

namespace local_groupdist;

use local_groupdist\local\fields;

/**
 * The Seats capacity of course groups, for other plugins.
 *
 * This is the one entry point outside this plugin's own code; everything under
 * local\ is internal and free to change. enrol_apply reaches it through
 * class_exists('\local_groupdist\seats'), so an install without it reads as
 * "no group declares seats" rather than failing. Keep the class name, the
 * method and the shape of its return.
 *
 * Nothing here checks a capability: seats and member counts are not personal
 * data, and the caller decides what to show and to whom.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seats {
    /**
     * Declared seats and current members of each given group.
     *
     * The member count is every row of the group, whatever the member's role or
     * the component that wrote it: the number the bulk edit page compares with
     * the seats when it marks a group as over capacity, so a consumer and that
     * page never disagree about the same group.
     *
     * @param array $groupids Group ids; duplicates and numeric strings are accepted.
     * @return array Group id => object with ?int 'seats' (null when the group declares
     *   no limit, or the Seats field is not provisioned) and int 'members'. A group id
     *   that does not exist comes back with null seats and 0 members.
     */
    public static function for_groups(array $groupids): array {
        $groupids = array_values(array_unique(array_map('intval', $groupids)));
        if (!$groupids) {
            return [];
        }

        $values = fields::get_group_values($groupids);
        $counts = fields::get_member_counts($groupids);

        $result = [];
        foreach ($groupids as $groupid) {
            $result[$groupid] = (object) [
                'seats' => $values[$groupid]->seats,
                'members' => $counts[$groupid] ?? 0,
            ];
        }
        return $result;
    }
}
