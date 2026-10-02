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

namespace local_groupdist\local;

use core\output\stored_progress_bar;

/**
 * Progress consumer that moves a stored progress bar.
 *
 * The same contract as core\progress\stored, which Moodle 4.5 does not have:
 * the apply task hands one to applier::apply() so each written chunk advances
 * the bar the status page polls. Used only where core lacks its own
 * ({@see \local_groupdist\task\apply_distribution::progress_reporter()}).
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stored_progress extends \core\progress\base {
    /** @var stored_progress_bar The started bar this consumer updates. */
    protected stored_progress_bar $bar;

    /**
     * Wrap a started stored progress bar.
     *
     * @param stored_progress_bar $bar The bar, already started, so it has a record to update.
     */
    public function __construct(stored_progress_bar $bar) {
        $this->bar = $bar;
    }

    /**
     * Write the current position, and the description of the section in progress, to the bar.
     *
     * @return void
     */
    protected function update_progress() {
        [$min] = $this->get_progress_proportion_range();
        $message = $this->is_in_progress_section() ? $this->get_current_description() : '';
        $this->bar->update_full($min * 100, $message);
    }
}
