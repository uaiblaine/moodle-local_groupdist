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
 * Injects the plugin's bulk actions on the group management page:
 * "Bulk edit groups" and, below it, "Distribute participants".
 *
 * Each control is a submit button with a formaction pointing at the plugin's
 * page: the browser posts the core form (groups[], id, sesskey) straight to
 * the plugin. They carry no name attribute on purpose — an unknown "action"
 * value makes group/index.php throw.
 *
 * @module     local_groupdist/index_button
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

const SELECTORS = {
    FORM: '#groupeditform',
    GROUPS: '#groups',
    DELETEBUTTON: '#deletegroup',
    OWNBUTTONS: '.local-groupdist-action',
};

/**
 * Build one injected action button.
 *
 * @param {String} id The element id.
 * @param {String} label The button label.
 * @param {String} url The formaction target.
 * @returns {HTMLElement} The wrapper div containing the button.
 */
const buildButton = (id, label, url) => {
    const button = document.createElement('button');
    button.type = 'submit';
    button.id = id;
    button.className = 'btn btn-secondary local-groupdist-action';
    button.textContent = label;
    button.disabled = true;
    button.formAction = url;

    const wrapper = document.createElement('div');
    wrapper.className = 'mb-3';
    wrapper.appendChild(button);
    return wrapper;
};

/**
 * Inject the buttons and mirror the selection-based enable/disable behaviour.
 *
 * @param {Number} courseid The course id.
 * @param {Boolean} candistribute Whether to offer the distribute action.
 * @param {Boolean} canbulkedit Whether to offer the bulk edit action.
 * @returns {Promise<void>}
 */
export const init = async(courseid, candistribute, canbulkedit) => {
    const form = document.querySelector(SELECTORS.FORM);
    const groups = document.querySelector(SELECTORS.GROUPS);
    if (!form || !groups || document.querySelector(SELECTORS.OWNBUTTONS)) {
        return;
    }

    const [bulklabel, distlabel] = await getStrings([
        {key: 'bulkeditgroups', component: 'local_groupdist'},
        {key: 'distributeparticipants', component: 'local_groupdist'},
    ]);

    const wrappers = [];
    if (canbulkedit) {
        wrappers.push(buildButton(
            'local-groupdist-bulkedit',
            bulklabel,
            M.cfg.wwwroot + '/local/groupdist/bulkedit.php?id=' + courseid
        ));
    }
    if (candistribute) {
        wrappers.push(buildButton(
            'local-groupdist-distribute',
            distlabel,
            M.cfg.wwwroot + '/local/groupdist/distribute.php?id=' + courseid
        ));
    }
    if (!wrappers.length) {
        return;
    }

    const anchor = form.querySelector(SELECTORS.DELETEBUTTON);
    const anchorwrapper = anchor ? anchor.closest('div') : null;
    let previous = anchorwrapper;
    wrappers.forEach((wrapper) => {
        if (previous) {
            previous.insertAdjacentElement('afterend', wrapper);
        } else {
            form.appendChild(wrapper);
        }
        previous = wrapper;
    });

    // Own listener only — the page's three legacy layers keep their own state.
    const sync = () => {
        const none = !groups.querySelector('option:checked');
        document.querySelectorAll(SELECTORS.OWNBUTTONS).forEach((button) => {
            button.disabled = none;
        });
    };
    groups.addEventListener('change', sync);
    sync();
};
