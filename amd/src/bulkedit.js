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
 * cells travel, at most CHUNK_SIZE per request, sequentially) with per-cell
 * validation messages, mass apply for the seats field, empty-seats filter,
 * per-user column visibility and the dynamic overbooking indicator on the
 * members column.
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
    CELLERROR: '[data-region="cellerror"]',
};

const CHUNK_SIZE = 100;
const PREFERENCE = 'local_groupdist_bulkedit_hiddencols';

const state = {
    courseid: 0,
    seatsshortname: '',
    // Map of "groupid:shortname" => value, holding ONLY changed cells.
    dirty: new Map(),
    hiddencols: new Set(),
    // True while save() has requests in flight.
    saving: false,
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
 * The cell of one group and field.
 *
 * @param {Number} groupid The group id.
 * @param {String} shortname The custom field shortname.
 * @returns {Element|null} The td element.
 */
const cellAt = (groupid, shortname) => document.querySelector(
    'tbody tr[data-groupid="' + groupid + '"] td[data-shortname="' + shortname + '"]'
);

/**
 * Mark a cell the server refused, with core's message below its editor.
 *
 * @param {Element} cell The td element.
 * @param {String} message Why the value was not saved.
 */
const showCellError = (cell, message) => {
    const field = cell.querySelector('[data-fieldtype]');
    if (!field) {
        return;
    }
    let feedback = cell.querySelector(SELECTORS.CELLERROR);
    if (!feedback) {
        feedback = document.createElement('div');
        feedback.className = 'invalid-feedback';
        feedback.dataset.region = 'cellerror';
        feedback.id = 'local-groupdist-cellerror-' + cell.closest('tr').dataset.groupid + '-' + cell.dataset.shortname;
        field.after(feedback);
    }
    feedback.textContent = message;
    field.classList.add('is-invalid');
    field.setAttribute('aria-invalid', 'true');
    field.setAttribute('aria-describedby', feedback.id);
    if (state.hiddencols.has(cell.dataset.colkey)) {
        // A refused cell in a collapsed column could not be seen or corrected, so the column opens.
        setColumnVisible(cell.dataset.colkey, true, false);
        const toggle = document.querySelector(SELECTORS.TOGGLECOL + '[data-colkey="' + cell.dataset.colkey + '"]');
        if (toggle) {
            toggle.checked = true;
        }
    }
};

/**
 * Remove a cell's refusal mark, if it has one.
 *
 * @param {Element} cell The td element.
 */
const clearCellError = (cell) => {
    const feedback = cell.querySelector(SELECTORS.CELLERROR);
    if (!feedback) {
        return;
    }
    feedback.remove();
    const field = cell.querySelector('[data-fieldtype]');
    if (field) {
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
        field.removeAttribute('aria-describedby');
    }
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
    save.disabled = state.saving || state.dirty.size === 0;
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
    // The refusal described the previous value, not this one.
    clearCellError(cell);
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
 * Apply one save response to the dirty set and the cells.
 *
 * Each result is checked against the value the request carried, not the
 * server's normalised echo of it: a cell edited again while the request was in
 * flight keeps its newer value dirty, and a refusal of the old value is not
 * shown on it.
 *
 * @param {Array} sentchanges The changes the request carried.
 * @param {Object} response The web service response.
 */
const applyResponse = (sentchanges, response) => {
    const keyOf = (item) => item.groupid + ':' + item.shortname;
    const sent = new Map(sentchanges.map((entry) => [keyOf(entry), entry.value]));
    const unchanged = (item) => state.dirty.get(keyOf(item)) === sent.get(keyOf(item));
    response.saved.filter(unchanged).forEach((item) => {
        state.dirty.delete(keyOf(item));
        const cell = cellAt(item.groupid, item.shortname);
        if (cell) {
            clearCellError(cell);
        }
    });
    response.errors.filter(unchanged).forEach((item) => {
        const cell = cellAt(item.groupid, item.shortname);
        if (cell) {
            showCellError(cell, item.message);
        }
    });
};

/**
 * Save every dirty cell, in sequential chunks of at most CHUNK_SIZE.
 *
 * The values sent are a snapshot taken when the save starts, and the editors
 * stay live while the requests run; applyResponse() keeps any cell edited in
 * the meantime dirty. A cell the server refused stays dirty too, marked with
 * the reason, and a mid-way failure keeps exactly the unsaved remainder for a
 * retry.
 *
 * @returns {Promise<void>}
 */
const save = async() => {
    // A second save would start from a snapshot the first one is still writing.
    if (state.saving) {
        return;
    }
    state.saving = true;
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
    let refused = 0;
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
            applyResponse(chunks[i], response);
            saved += response.saved.length;
            refused += response.errors.length;
        }
        if (saved > 0 || refused === 0) {
            addToast(await getString('savedchanges', 'local_groupdist', saved), {type: 'success'});
        }
        if (refused > 0) {
            addToast(await getString('savefailedcells', 'local_groupdist', refused), {
                type: 'danger',
                autohide: false,
                closeButton: true,
            });
        }
    } catch (error) {
        Notification.exception(error);
    } finally {
        state.saving = false;
        savebutton.textContent = original;
    }
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
        fresh.className = 'local-groupdist-gavatar local-groupdist-ginitial rounded-circle bg-secondary text-dark';
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
    badge.className = 'badge bg-light text-dark border fw-normal local-groupdist-idn text-truncate';
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
        clearCellError(td);
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

    // Saved cells are already written, so leaving loses only unsaved edits.
    // Confirm that once: "Back to groups" does not read as discarding them.
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
