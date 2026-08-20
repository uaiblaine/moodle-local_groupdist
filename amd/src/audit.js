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
 * Audit report enhancement: live search over a run's group sections, paging
 * swapped in place, and lazy loading of long group memberships.
 *
 * Every control this module drives already works without it — the search boxes
 * are a GET form, the paging bar is a set of links and each group's "show
 * more" is a link to that group's own page. Enhancing rather than replacing
 * them keeps the report bookmarkable and reload-safe, which matters more here
 * than on the preview screen: this is an immutable record under Course →
 * Reports, not a transient computation.
 *
 * A run may carry thousands of participants across hundreds of groups, so only
 * ever one page of sections is in the document.
 *
 * @module     local_groupdist/audit
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {getString} from 'core/str';

const SELECTORS = {
    REGION: '[data-region="local-groupdist-audit"]',
    FILTERS: '[data-region="filters"]',
    USERQUERY: '[data-region="userquery"]',
    GROUPQUERY: '[data-region="groupquery"]',
    SECTIONS: '[data-region="sections"]',
    SECTION: '[data-region="section"]',
    MEMBERS: '[data-region="members"]',
    SECTIONMORE: '[data-region="sectionmore"]',
    COUNTER: '[data-region="counter"]',
    EMPTY: '[data-region="empty"]',
    PAGINGBAR: '[data-region="pagingbar"]',
    LOADMEMBERS: '[data-action="loadmembers"]',
};

const DEBOUNCE_MS = 400;

const state = {
    config: null,
    busy: false,
    timer: null,
};

/**
 * The current search box values.
 *
 * @param {Element} root The audit region.
 * @returns {Object} Keys userquery and groupquery.
 */
const queries = (root) => {
    const user = root.querySelector(SELECTORS.USERQUERY);
    const group = root.querySelector(SELECTORS.GROUPQUERY);
    return {
        userquery: user ? user.value.trim() : '',
        groupquery: group ? group.value.trim() : '',
    };
};

/**
 * The browser URL describing the state currently on screen.
 *
 * @param {Element} root The audit region.
 * @param {Number} page Zero-based page of sections.
 * @returns {String} The URL.
 */
const stateUrl = (root, page) => {
    const url = new URL(window.location.href);
    const {userquery, groupquery} = queries(root);
    url.searchParams.set('id', state.config.courseid);
    url.searchParams.set('run', state.config.runid);
    [['uq', userquery], ['gq', groupquery], ['page', page > 0 ? String(page) : '']]
        .forEach(([key, value]) => {
            if (value === '') {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, value);
            }
        });
    return url.toString();
};

/**
 * Load one page of sections and swap it in.
 *
 * @param {Element} root The audit region.
 * @param {Number} page Zero-based page of sections.
 * @param {Boolean} push Whether the browser history gains an entry.
 * @returns {Promise<void>}
 */
const loadSections = async(root, page, push) => {
    if (state.busy) {
        return;
    }
    state.busy = true;
    const region = root.querySelector(SELECTORS.SECTIONS);
    region.setAttribute('aria-busy', 'true');

    try {
        const data = await Ajax.call([{
            methodname: 'local_groupdist_get_audit_sections',
            args: {
                runid: state.config.runid,
                courseid: state.config.courseid,
                page,
                ...queries(root),
            },
        }])[0];

        const {html, js} = await Templates.renderForPromise('local_groupdist/audit_sections', {
            sections: data.sections,
        });
        Templates.replaceNodeContents(region, html, js);
        root.querySelector(SELECTORS.PAGINGBAR).innerHTML = data.pagingbar;
        root.querySelector(SELECTORS.EMPTY).hidden = data.total > 0;
        root.querySelector(SELECTORS.COUNTER).textContent = await getString(
            'auditcountergroups',
            'local_groupdist',
            {shown: data.shown, total: data.total, members: data.matchingmembers}
        );

        const url = stateUrl(root, page);
        if (push) {
            window.history.pushState({page}, '', url);
        } else {
            window.history.replaceState({page}, '', url);
        }
    } catch (error) {
        Notification.exception(error);
    } finally {
        region.removeAttribute('aria-busy');
        state.busy = false;
    }
};

/**
 * Append one window of members to a section card.
 *
 * @param {Element} root The audit region.
 * @param {Element} section The section card.
 * @returns {Promise<void>}
 */
const loadMembers = async(root, section) => {
    const link = section.querySelector(SELECTORS.LOADMEMBERS);
    link.classList.add('disabled');

    try {
        const data = await Ajax.call([{
            methodname: 'local_groupdist_get_audit_members',
            args: {
                runid: state.config.runid,
                courseid: state.config.courseid,
                groupid: parseInt(section.dataset.groupid, 10),
                limitfrom: parseInt(section.dataset.shown, 10),
                userquery: queries(root).userquery,
            },
        }])[0];

        const {html, js} = await Templates.renderForPromise('local_groupdist/audit_members', {
            members: data.members,
        });
        Templates.appendNodeContents(section.querySelector(SELECTORS.MEMBERS), html, js);
        section.dataset.shown = data.shown;
        section.dataset.total = data.total;

        if (data.shown >= data.total || data.members.length === 0) {
            section.querySelector(SELECTORS.SECTIONMORE).remove();
            return;
        }
        link.classList.remove('disabled');
        link.textContent = await getString(
            'auditshowmoremembers',
            'local_groupdist',
            data.total - data.shown
        );
    } catch (error) {
        link.classList.remove('disabled');
        Notification.exception(error);
    }
};

/**
 * The zero-based page a paging bar link points at.
 *
 * @param {HTMLAnchorElement} link The link.
 * @returns {Number} The page, or -1 when the link carries none.
 */
const pageOf = (link) => {
    const page = new URL(link.href, window.location.origin).searchParams.get('page');
    return page === null ? 0 : parseInt(page, 10);
};

/**
 * Initialise the audit report region.
 *
 * @returns {Promise<void>}
 */
export const init = async() => {
    const root = document.querySelector(SELECTORS.REGION);
    if (!root) {
        return;
    }
    state.config = JSON.parse(root.dataset.config);

    // Walking one pinned group is a server-paged view of its own; only the
    // per-section "show more" control is enhanced there.
    if (state.config.pinned < 0) {
        const form = root.querySelector(SELECTORS.FILTERS);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            window.clearTimeout(state.timer);
            loadSections(root, 0, true);
        });
        form.addEventListener('input', (event) => {
            if (event.target.matches(`${SELECTORS.USERQUERY}, ${SELECTORS.GROUPQUERY}`)) {
                window.clearTimeout(state.timer);
                state.timer = window.setTimeout(() => loadSections(root, 0, false), DEBOUNCE_MS);
            }
        });

        root.querySelector(SELECTORS.PAGINGBAR).addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link) {
                return;
            }
            event.preventDefault();
            loadSections(root, pageOf(link), true);
        });

        window.addEventListener('popstate', () => {
            // The URL is the record of what was on screen, so the boxes follow
            // it back rather than the other way round.
            const params = new URL(window.location.href).searchParams;
            [[SELECTORS.USERQUERY, 'uq'], [SELECTORS.GROUPQUERY, 'gq']].forEach(([selector, key]) => {
                const input = root.querySelector(selector);
                if (input) {
                    input.value = params.get(key) || '';
                }
            });
            const page = params.get('page');
            loadSections(root, page === null ? 0 : parseInt(page, 10), false);
        });
    }

    root.addEventListener('click', (event) => {
        const link = event.target.closest(SELECTORS.LOADMEMBERS);
        if (!link || link.classList.contains('disabled')) {
            return;
        }
        event.preventDefault();
        loadMembers(root, link.closest(SELECTORS.SECTION));
    });
};
