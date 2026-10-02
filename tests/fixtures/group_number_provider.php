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

namespace local_groupdist\fixtures;

/**
 * A number field provider offered for group custom fields, as a third-party
 * plugin could register through \customfield_number\hook\add_custom_providers.
 *
 * Core's only provider, nofactivities, is available for course fields alone,
 * so this is what makes a provider-backed group number field reachable.
 *
 * @package    local_groupdist
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_number_provider extends \customfield_number\provider_base {
    /**
     * Provider name.
     *
     * @return string The name.
     */
    public function get_name(): string {
        return 'Group fixture provider';
    }

    /**
     * Offered for group custom fields only.
     *
     * @return bool Whether the field belongs to the group handler.
     */
    public function is_available(): bool {
        return $this->field->get_handler()->get_component() === 'core_group';
    }
}
