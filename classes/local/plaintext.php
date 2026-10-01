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
 * Admin- and user-set strings in their plain spelling.
 *
 * The plain spelling is for sinks that escape for themselves or are
 * transparent to entities: Mustache double stashes, textContent in AMD
 * modules, {{#str}} parameters and PARAM_TEXT web service fields. Sinks that
 * render raw (a moodleform element label, a static element, a select's
 * options) need the escaped spelling and must not come through here; see
 * fields::get_seats_label() and profilefields::get_source_groups(), which
 * take an $escape switch for them.
 *
 * format_string(..., escape => false) alone is plain text only while the site
 * setting formatstringstriptags is on (the default), which strips the tags.
 * With it off, core returns clean_text() HTML instead
 * ({@see \core\formatting::format_string()}): markup survives, which makes
 * clean_returnvalue() throw on a PARAM_TEXT field and fail the whole
 * response, and a bare "&" or "<" comes back as "&amp;" or "&lt;", which
 * every sink above would show literally. So with the setting off the tags
 * are stripped and the entities decoded here, giving what the setting-on path
 * gives ("A & B" stays "A & B"). A final PARAM_TEXT pass then makes the result
 * a fixed point of that cleaner: a decoded "<3" goes, exactly as strip_tags()
 * removes it with the setting on.
 *
 * Apply it once, to the stored string: a second pass would run the filters
 * again and, with the setting off, decode a second time any entity the
 * stored text spelled out literally.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plaintext {
    /**
     * Format a stored string in its plain spelling.
     *
     * @param string $text The stored text.
     * @param \core\context $context The context to format and filter it in.
     * @return string Plain text, not HTML-escaped, that PARAM_TEXT leaves unchanged.
     */
    public static function format(string $text, \core\context $context): string {
        $formatted = format_string($text, true, ['context' => $context, 'escape' => false]);
        if (!\core\di::get(\core\formatting::class)->get_striptags()) {
            $formatted = html_entity_decode(strip_tags($formatted), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return clean_param($formatted, PARAM_TEXT);
    }
}
