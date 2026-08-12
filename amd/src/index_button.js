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
 * Injects the "Distribute participants" bulk action on the group management page.
 *
 * The control is a submit button with a formaction pointing at the plugin's
 * distribute.php: the browser posts the core form (groups[], id, sesskey)
 * straight to the plugin. It carries no name attribute on purpose — an
 * unknown "action" value makes group/index.php throw.
 *
 * @module     local_groupdist/index_button
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';

const SELECTORS = {
    FORM: '#groupeditform',
    GROUPS: '#groups',
    DELETEBUTTON: '#deletegroup',
    OWNBUTTON: '#local-groupdist-distribute',
};

/**
 * Inject the button and mirror the selection-based enable/disable behaviour.
 *
 * @param {Number} courseid The course id.
 * @returns {Promise<void>}
 */
export const init = async(courseid) => {
    const form = document.querySelector(SELECTORS.FORM);
    const groups = document.querySelector(SELECTORS.GROUPS);
    if (!form || !groups || document.querySelector(SELECTORS.OWNBUTTON)) {
        return;
    }

    const label = await getString('distributeparticipants', 'local_groupdist');

    const button = document.createElement('button');
    button.type = 'submit';
    button.id = 'local-groupdist-distribute';
    button.className = 'btn btn-secondary';
    button.textContent = label;
    button.disabled = true;
    button.formAction = M.cfg.wwwroot + '/local/groupdist/distribute.php?id=' + courseid;

    const wrapper = document.createElement('div');
    wrapper.className = 'mb-3';
    wrapper.appendChild(button);

    const anchor = form.querySelector(SELECTORS.DELETEBUTTON);
    const anchorwrapper = anchor ? anchor.closest('div') : null;
    if (anchorwrapper) {
        anchorwrapper.insertAdjacentElement('afterend', wrapper);
    } else {
        form.appendChild(wrapper);
    }

    // Own listener only — the page's three legacy layers keep their own state.
    const sync = () => {
        button.disabled = !groups.querySelector('option:checked');
    };
    groups.addEventListener('change', sync);
    sync();
};
