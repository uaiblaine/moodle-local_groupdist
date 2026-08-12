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

use core\hook\output\before_footer_html_generation;

/**
 * Hook callbacks for local_groupdist.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Inject the "Distribute participants" button on the group management page.
     *
     * The group index page offers no server-side extension point (its renderable
     * is built inline and no page hook is dispatched), so the button is added by
     * an AMD module. This hook fires before the page requirements are finalised,
     * so js_call_amd() is still safe here on both 5.1 and 5.2.
     *
     * @param before_footer_html_generation $hook The hook instance.
     * @return void
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE;

        // Cheapest guard first: this hook runs on every non-AJAX page of the site.
        if ($PAGE->pagetype !== 'group-index') {
            return;
        }

        // Redirect interstitials rendered from group/index.php keep the same
        // pagetype but switch the layout; never inject UI into those.
        if ($PAGE->pagelayout !== 'standard') {
            return;
        }

        $context = $PAGE->context;
        if (!($context instanceof \core\context\course)) {
            return;
        }

        if (!has_capability('local/groupdist:distribute', $context)) {
            return;
        }

        $PAGE->requires->js_call_amd('local_groupdist/index_button', 'init', [$context->instanceid]);
    }
}
