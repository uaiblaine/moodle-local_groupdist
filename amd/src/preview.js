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
 * Preview hydration: fetches distribution pages from the preview web service
 * and renders stats, warnings and group cards client-side — one rendering
 * path, the web service return structure is the single source of truth.
 *
 * @module     local_groupdist/preview
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {getString} from 'core/str';

const SELECTORS = {
    REGION: '[data-region="local-groupdist-preview"]',
    STATS: '[data-region="stats"]',
    WARNINGS: '[data-region="warnings"]',
    GROUPS: '[data-region="groups"]',
    LOADMORE: '[data-action="loadmore"]',
    COUNTER: '[data-region="counter"]',
    CAPNOTE: '[data-region="capnote"]',
    SKELETONS: '[data-region="skeleton"]',
    FINGERPRINT: '[data-region="fingerprint"]',
    APPLY: '[data-action="apply"]',
};

const PAGESIZE = 5;

const state = {
    options: null,
    offset: 0,
    total: 0,
    shownmax: 0,
    fingerprint: null,
    stale: false,
};

/**
 * Call the preview web service for one window of groups.
 *
 * @param {Number} limitfrom Window offset.
 * @param {Number} limitnum Window size.
 * @returns {Promise<Object>} The preview payload.
 */
const fetchPage = (limitfrom, limitnum) => Ajax.call([{
    methodname: 'local_groupdist_get_preview',
    args: {...state.options, limitfrom, limitnum},
}])[0];

/**
 * Render an alert into the warnings region.
 *
 * @param {Element} region The warnings region.
 * @param {String} message The message text.
 * @param {String} level Bootstrap alert level suffix.
 */
const addAlert = (region, message, level = 'warning') => {
    const alert = document.createElement('div');
    alert.className = 'alert alert-' + level + ' py-2 small';
    alert.setAttribute('role', 'alert');
    alert.textContent = message;
    region.appendChild(alert);
};

/**
 * First-page-only rendering: stats tiles, warnings, apply enablement.
 *
 * @param {Element} root The preview region.
 * @param {Object} data The web service payload.
 * @returns {Promise<void>}
 */
const renderHeader = async(root, data) => {
    const {html, js} = await Templates.renderForPromise('local_groupdist/preview_stats', {
        ...data.totals,
        hasseats: data.totals.seatstotal >= 0,
        warningcount: data.warnings.length,
    });
    Templates.replaceNodeContents(root.querySelector(SELECTORS.STATS), html, js);

    const warningsregion = root.querySelector(SELECTORS.WARNINGS);
    warningsregion.textContent = '';
    data.warnings.forEach((warning) => addAlert(warningsregion, warning.message));

    state.fingerprint = data.fingerprint;
    document.querySelectorAll(SELECTORS.FINGERPRINT).forEach((input) => {
        input.value = data.fingerprint;
    });
    document.querySelectorAll(SELECTORS.APPLY).forEach((button) => {
        button.disabled = data.totals.memberships === 0;
    });
};

/**
 * Load and append one page of groups.
 *
 * @param {Element} root The preview region.
 * @returns {Promise<void>}
 */
const loadPage = async(root) => {
    const groupsregion = root.querySelector(SELECTORS.GROUPS);
    const loadmore = root.querySelector(SELECTORS.LOADMORE);
    const first = state.offset === 0;

    loadmore.disabled = true;
    const skeleton = await Templates.renderForPromise('local_groupdist/preview_skeleton', {
        cards: new Array(first ? 3 : PAGESIZE).fill({}),
    });
    Templates.appendNodeContents(groupsregion, skeleton.html, skeleton.js);

    try {
        const data = await fetchPage(state.offset, PAGESIZE);
        groupsregion.querySelectorAll(SELECTORS.SKELETONS).forEach((node) => node.remove());

        if (first) {
            await renderHeader(root, data);
        } else if (data.fingerprint !== state.fingerprint && !state.stale) {
            // The course changed while paging: the pages no longer belong to
            // one consistent plan. Block apply and tell the teacher, once.
            state.stale = true;
            addAlert(root.querySelector(SELECTORS.WARNINGS), await getString('previewstale', 'local_groupdist'), 'danger');
            document.querySelectorAll(SELECTORS.APPLY).forEach((button) => {
                button.disabled = true;
            });
        }

        state.total = data.total;
        state.shownmax = data.shownmax;
        state.offset += data.groups.length;

        const {html, js} = await Templates.renderForPromise('local_groupdist/preview_groups', {groups: data.groups});
        Templates.appendNodeContents(groupsregion, html, js);

        const counter = root.querySelector(SELECTORS.COUNTER);
        counter.textContent = await getString('showinggroups', 'local_groupdist', {
            shown: Math.min(state.offset, state.total),
            total: state.total,
        });

        const exhausted = state.offset >= state.shownmax || data.groups.length === 0 || state.stale;
        loadmore.hidden = exhausted;
        loadmore.disabled = false;
        if (exhausted && data.capped) {
            const capnote = root.querySelector(SELECTORS.CAPNOTE);
            capnote.textContent = await getString('previewcapped', 'local_groupdist', {
                cap: state.shownmax,
                total: state.total,
            });
            capnote.hidden = false;
        }
    } catch (error) {
        groupsregion.querySelectorAll(SELECTORS.SKELETONS).forEach((node) => node.remove());
        // The offset was not advanced, so the button retries the same page —
        // unhide it even on a first-page failure.
        loadmore.hidden = false;
        loadmore.disabled = false;
        Notification.exception(error);
    }
};

/**
 * Initialise the preview region.
 *
 * @returns {Promise<void>}
 */
export const init = async() => {
    const root = document.querySelector(SELECTORS.REGION);
    if (!root) {
        return;
    }
    state.options = JSON.parse(root.dataset.options);

    // One apply only: a second click after submission would re-POST and end in
    // a confusing "nothing was applied" bounce.
    document.querySelectorAll(SELECTORS.APPLY).forEach((button) => {
        if (button.form) {
            button.form.addEventListener('submit', () => {
                window.setTimeout(() => {
                    button.disabled = true;
                }, 0);
            });
        }
    });

    root.querySelector(SELECTORS.LOADMORE).addEventListener('click', () => loadPage(root));
    await loadPage(root);
};
