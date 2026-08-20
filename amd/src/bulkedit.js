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
 * Bulk edit table behaviour: dirty tracking, chunked saves (only changed
 * cells travel, at most CHUNK_SIZE per request, sequentially), mass apply
 * for the seats field, empty-seats filter, per-user column visibility and
 * the dynamic overbooking indicator on the members column.
 *
 * @module     local_groupdist/bulkedit
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalForm from 'core_form/modalform';
import Notification from 'core/notification';
import Tooltip from 'theme_boost/bootstrap/tooltip';
import {add as addToast} from 'core/toast';
import {getString} from 'core/str';
import {setUserPreference} from 'core_user/repository';

const SELECTORS = {
    REGION: '[data-region="local-groupdist-bulkedit"]',
    ROWS: 'tbody tr[data-groupid]',
    CELL: 'td[data-shortname]',
    MEMCELL: '[data-region="memcell"]',
    OVERBADGE: '[data-region="overbadge"]',
    MASSVALUE: '[data-region="massvalue"]',
    MASSAPPLY: '[data-action="massapply"]',
    FILTER: '[data-action="filterempty"]',
    TOGGLECOL: '[data-action="togglecol"]',
    HIDDENCOUNT: '[data-region="hiddencount"]',
    EDITGROUP: '[data-action="editgroup"]',
    SAVE: '[data-action="save"]',
    BACK: '[data-action="backtogroups"]',
    DIRTYCOUNT: '[data-region="dirtycount"]',
    TOOLTIPS: '[data-bs-toggle="tooltip"]',
    IDCELL: 'td[data-colkey="id"]',
    IDBADGE: '.local-groupdist-idn',
};

const CHUNK_SIZE = 100;
const PREFERENCE = 'local_groupdist_bulkedit_hiddencols';

const state = {
    courseid: 0,
    seatsshortname: '',
    // Map of "groupid:shortname" => value, holding ONLY changed cells.
    dirty: new Map(),
    hiddencols: new Set(),
};

/**
 * Current value of an inline editor, normalised for the web service.
 *
 * @param {Element} cell The td element.
 * @returns {String} The value.
 */
const cellValue = (cell) => {
    const field = cell.querySelector('[data-fieldtype]');
    if (!field) {
        return '';
    }
    if (field.dataset.fieldtype === 'checkbox') {
        return field.checked ? '1' : '0';
    }
    return field.value;
};

/**
 * Recompute the seats-empty highlight and the overbooking indicator of a row.
 *
 * @param {Element} row The tr element.
 */
const syncRowIndicators = (row) => {
    const seatscell = row.querySelector('td[data-shortname="' + state.seatsshortname + '"]');
    if (!seatscell) {
        return;
    }
    const raw = cellValue(seatscell).trim();
    seatscell.classList.toggle('table-warning', raw === '');

    const members = parseInt(row.dataset.members, 10);
    const seats = raw === '' ? null : parseInt(raw, 10);
    const over = (seats !== null && members > seats) ? members - seats : 0;
    const memcell = row.querySelector(SELECTORS.MEMCELL);
    memcell.classList.toggle('table-danger', over > 0);
    const badge = memcell.querySelector(SELECTORS.OVERBADGE);
    badge.hidden = over === 0;
    badge.textContent = '+' + over;
};

/**
 * Refresh the unsaved-changes counter and the save button state.
 *
 * @returns {Promise<void>}
 */
const refreshChrome = async() => {
    const counter = document.querySelector(SELECTORS.DIRTYCOUNT);
    const save = document.querySelector(SELECTORS.SAVE);
    const groups = new Set([...state.dirty.keys()].map((key) => key.split(':')[0]));
    document.querySelectorAll(SELECTORS.ROWS).forEach((row) => {
        row.classList.toggle('local-groupdist-dirty', groups.has(row.dataset.groupid));
    });
    save.disabled = state.dirty.size === 0;
    counter.textContent = groups.size === 0 ? ''
        : await getString('unsavedchanges', 'local_groupdist', groups.size);
};

/**
 * Register a cell edit.
 *
 * @param {Element} cell The td element.
 * @returns {Promise<void>}
 */
const onCellEdit = async(cell) => {
    const row = cell.closest('tr');
    state.dirty.set(row.dataset.groupid + ':' + cell.dataset.shortname, cellValue(cell));
    if (cell.dataset.shortname === state.seatsshortname) {
        syncRowIndicators(row);
    }
    applyFilter();
    await refreshChrome();
};

/**
 * Apply the "only groups without seats" filter (dirty rows stay visible).
 */
const applyFilter = () => {
    const active = document.querySelector(SELECTORS.FILTER).checked;
    const dirtygroups = new Set([...state.dirty.keys()].map((key) => key.split(':')[0]));
    document.querySelectorAll(SELECTORS.ROWS).forEach((row) => {
        const seatscell = row.querySelector('td[data-shortname="' + state.seatsshortname + '"]');
        const noseats = seatscell && cellValue(seatscell).trim() === '';
        row.classList.toggle('d-none', active && !noseats && !dirtygroups.has(row.dataset.groupid));
    });
};

/**
 * Show or hide one column and persist the preference.
 *
 * @param {String} key The column key.
 * @param {Boolean} visible Whether the column shows.
 * @param {Boolean} persist Whether to store the preference.
 */
const setColumnVisible = (key, visible, persist) => {
    document.querySelectorAll('[data-colkey="' + key + '"]').forEach((el) => {
        el.classList.toggle('local-groupdist-colhidden', !visible);
    });
    if (visible) {
        state.hiddencols.delete(key);
    } else {
        state.hiddencols.add(key);
    }
    const badge = document.querySelector(SELECTORS.HIDDENCOUNT);
    badge.hidden = state.hiddencols.size === 0;
    badge.textContent = String(state.hiddencols.size);
    if (persist) {
        setUserPreference(PREFERENCE, [...state.hiddencols].join(','))
            .catch(Notification.exception);
    }
};

/**
 * Confirm leaving the page while cells are still unsaved.
 *
 * @param {String} url Where to go once the reader confirms.
 * @returns {Promise<void>}
 */
const confirmLeave = async(url) => {
    const groups = new Set([...state.dirty.keys()].map((key) => key.split(':')[0]));
    const [title, question, leave] = await Promise.all([
        getString('unsavedtitle', 'local_groupdist'),
        getString('unsavedleave', 'local_groupdist', groups.size),
        getString('unsavedleavebutton', 'local_groupdist'),
    ]);
    Notification.saveCancel(title, question, leave, () => {
        window.location.href = url;
    });
};

/**
 * Save every dirty cell, in sequential chunks of at most CHUNK_SIZE.
 *
 * Successfully saved cells leave the dirty set as each chunk completes, so a
 * mid-way failure retains exactly the unsaved remainder for a retry.
 *
 * @returns {Promise<void>}
 */
const save = async() => {
    const savebutton = document.querySelector(SELECTORS.SAVE);
    const original = savebutton.textContent;
    savebutton.disabled = true;

    const entries = [...state.dirty.entries()].map(([key, value]) => {
        const [groupid, shortname] = key.split(':');
        return {groupid: parseInt(groupid, 10), shortname, value: String(value)};
    });
    const chunks = [];
    for (let i = 0; i < entries.length; i += CHUNK_SIZE) {
        chunks.push(entries.slice(i, i + CHUNK_SIZE));
    }

    let saved = 0;
    try {
        for (let i = 0; i < chunks.length; i++) {
            if (chunks.length > 1) {
                savebutton.textContent = await getString('savingprogress', 'local_groupdist', {
                    done: i + 1,
                    total: chunks.length,
                });
            }
            const response = await Ajax.call([{
                methodname: 'local_groupdist_save_group_fields',
                args: {courseid: state.courseid, changes: chunks[i]},
            }])[0];
            for (const item of response.saved) {
                state.dirty.delete(item.groupid + ':' + item.shortname);
            }
            saved += response.saved.length;
        }
        addToast(await getString('savedchanges', 'local_groupdist', saved), {type: 'success'});
    } catch (error) {
        Notification.exception(error);
    }
    savebutton.textContent = original;
    await refreshChrome();
    applyFilter();
};

/**
 * Swap a row's avatar after the settings modal changed the group picture.
 * The two states are different elements — an img when there is a picture, a
 * span holding the initial when there is not — so this replaces the node
 * rather than setting a src.
 *
 * @param {Element} row The tr element.
 * @param {Object} data The row context from the dynamic form.
 */
const updateAvatar = (row, data) => {
    const current = row.querySelector('.local-groupdist-gavatar');
    if (!current) {
        return;
    }
    const fresh = document.createElement(data.pictureurl ? 'img' : 'span');
    if (data.pictureurl) {
        fresh.className = 'local-groupdist-gavatar rounded-circle';
        fresh.src = data.pictureurl;
        fresh.alt = '';
    } else {
        fresh.className = 'local-groupdist-gavatar local-groupdist-ginitial rounded-circle bg-secondary text-white';
        fresh.setAttribute('aria-hidden', 'true');
        fresh.textContent = data.initial;
    }
    current.replaceWith(fresh);
};

/**
 * Rebuild a row's ID number badge after the settings modal changed it.
 *
 * The badge is present only when the group has an ID number, and Bootstrap
 * moves a tooltip's title into its own state at init, so a live tooltip does
 * not notice a changed title attribute. Replacing the node and re-initialising
 * covers all three transitions — changed, cleared, newly set — with one path.
 *
 * @param {Element} row The tr element.
 * @param {Object} data The row context from the dynamic form.
 */
const updateIdnumber = (row, data) => {
    const cell = row.querySelector(SELECTORS.IDCELL);
    if (!cell) {
        return;
    }
    const current = cell.querySelector(SELECTORS.IDBADGE);
    if (current) {
        const tooltip = Tooltip.getInstance(current);
        if (tooltip) {
            tooltip.dispose();
        }
        current.remove();
    }
    if (!data.idnumber) {
        return;
    }
    const badge = document.createElement('span');
    badge.className = 'badge bg-light text-muted border fw-normal local-groupdist-idn text-truncate';
    badge.tabIndex = 0;
    badge.setAttribute('data-bs-toggle', 'tooltip');
    badge.setAttribute('title', data.idnumber);
    badge.textContent = data.idnumber;
    cell.appendChild(badge);
    new Tooltip(badge);
};

/**
 * Update a row's cells from a fresh server-side row context (after the
 * settings modal saved).
 *
 * @param {Element} row The tr element.
 * @param {Object} data The row context from the dynamic form.
 */
const updateRow = (row, data) => {
    row.querySelector('.local-groupdist-gname').textContent = data.name;
    row.querySelector('.local-groupdist-gname').setAttribute('title', data.name);
    updateAvatar(row, data);
    updateIdnumber(row, data);
    data.cells.forEach((cell) => {
        const td = row.querySelector('td[data-shortname="' + cell.shortname + '"]');
        if (!td) {
            return;
        }
        const field = td.querySelector('[data-fieldtype]');
        if (field && field.dataset.fieldtype === 'checkbox') {
            field.checked = cell.checked;
        } else if (field) {
            field.value = cell.value;
        } else {
            const readonly = td.querySelector('.local-groupdist-readonly');
            if (readonly) {
                readonly.innerHTML = cell.displayvalue;
            }
        }
        // The modal saved directly: whatever this cell held locally is stale.
        state.dirty.delete(row.dataset.groupid + ':' + cell.shortname);
    });
    syncRowIndicators(row);
};

/**
 * Open the group settings modal for one row.
 *
 * @param {Element} button The clicked edit button.
 * @returns {Promise<void>}
 */
const openSettings = async(button) => {
    const modal = new ModalForm({
        formClass: 'local_groupdist\\form\\group_settings_form',
        args: {groupid: parseInt(button.dataset.groupid, 10)},
        modalConfig: {
            title: await getString('editgroupsettings', 'local_groupdist', button.dataset.groupname),
        },
        returnFocus: button,
    });
    modal.addEventListener(modal.events.FORM_SUBMITTED, (event) => {
        const row = button.closest('tr');
        updateRow(row, event.detail);
        button.dataset.groupname = event.detail.name;
        refreshChrome().catch(Notification.exception);
    });
    modal.show();
};

/**
 * Initialise the bulk edit table.
 *
 * @returns {Promise<void>}
 */
export const init = async() => {
    const region = document.querySelector(SELECTORS.REGION);
    if (!region) {
        return;
    }
    state.courseid = parseInt(region.dataset.courseid, 10);
    state.seatsshortname = region.dataset.seatsshortname;
    region.dataset.hiddencols.split(',').filter(Boolean).forEach((key) => {
        setColumnVisible(key, false, false);
        const checkbox = region.querySelector(SELECTORS.TOGGLECOL + '[data-colkey="' + key + '"]');
        if (checkbox) {
            checkbox.checked = false;
        }
    });

    region.querySelectorAll(SELECTORS.TOOLTIPS).forEach((el) => new Tooltip(el));

    region.addEventListener('input', (event) => {
        const cell = event.target.closest(SELECTORS.CELL);
        if (cell && event.target.matches('input[data-fieldtype]')) {
            onCellEdit(cell).catch(Notification.exception);
        }
    });
    region.addEventListener('change', (event) => {
        const cell = event.target.closest(SELECTORS.CELL);
        if (cell && event.target.matches('select[data-fieldtype], input[data-fieldtype="checkbox"]')) {
            onCellEdit(cell).catch(Notification.exception);
        }
        if (event.target.matches(SELECTORS.TOGGLECOL)) {
            setColumnVisible(event.target.dataset.colkey, event.target.checked, true);
        }
        if (event.target.matches(SELECTORS.FILTER)) {
            applyFilter();
        }
    });

    region.querySelector(SELECTORS.MASSAPPLY).addEventListener('click', () => {
        const value = region.querySelector(SELECTORS.MASSVALUE).value;
        if (value === '') {
            return;
        }
        region.querySelectorAll(SELECTORS.ROWS).forEach((row) => {
            const cell = row.querySelector('td[data-shortname="' + state.seatsshortname + '"]');
            if (cell) {
                cell.querySelector('input').value = value;
                onCellEdit(cell).catch(Notification.exception);
            }
        });
    });

    region.addEventListener('click', (event) => {
        const button = event.target.closest(SELECTORS.EDITGROUP);
        if (button) {
            openSettings(button).catch(Notification.exception);
        }
    });

    document.querySelector(SELECTORS.SAVE).addEventListener('click', () => {
        save().catch(Notification.exception);
    });

    /* Cells are written as they are saved, so leaving discards only what has
       not been saved yet — which is worth one confirmation, because the
       control that leaves no longer reads as "cancel". */
    const back = document.querySelector(SELECTORS.BACK);
    if (back) {
        back.addEventListener('click', (event) => {
            if (state.dirty.size === 0) {
                return;
            }
            event.preventDefault();
            confirmLeave(back.href).catch(Notification.exception);
        });
    }
};
