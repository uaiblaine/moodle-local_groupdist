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

/**
 * Tells styles.css which Bootstrap major version the site runs.
 *
 * Moodle 4.5 ships Bootstrap 4 and 5.0+ ship Bootstrap 5. 4.5's forward
 * bridge (theme/boost/scss/moodle/bs5-bridge.scss) covers only g-0,
 * btn-close, the ms/me/ps/pe spacers and float/text/border/rounded-start/end,
 * so the other Bootstrap 5 classes the plugin's markup uses resolve to nothing
 * there. The tail of styles.css defines them behind the body class this adds.
 *
 * The gate keeps that polyfill off Bootstrap 5 sites. Plugin CSS is compiled
 * before the theme's, so only specificity decides, and an ungated rule of the
 * polyfill (body class plus utility) would outrank Bootstrap 5's own
 * one-class utility and impose 4.5's values there.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bootstrap {
    /** @var string Body class added on sites running Bootstrap 4 (Moodle 4.5). */
    public const BODY_CLASS_BS4 = 'local-groupdist-bs4';

    /** @var int First Moodle branch that ships Bootstrap 5. */
    private const FIRST_BS5_BRANCH = 500;

    /**
     * Whether the site runs Bootstrap 4 rather than Bootstrap 5.
     *
     * @return bool True on Moodle 4.5, false from Moodle 5.0 on.
     */
    public static function is_bs4(): bool {
        global $CFG;

        return (int) $CFG->branch < self::FIRST_BS5_BRANCH;
    }

    /**
     * Add the Bootstrap 4 marker to the page when the site needs the polyfill.
     *
     * Call it from every plugin page that prints a header, before the header:
     * add_body_class() throws once output has started, which is also why the
     * injected button on group/index.php uses no polyfilled class.
     *
     * @return void
     */
    public static function mark_page(): void {
        global $PAGE;

        if (self::is_bs4()) {
            $PAGE->add_body_class(self::BODY_CLASS_BS4);
        }
    }
}
