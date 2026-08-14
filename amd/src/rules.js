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
 * Affinity rule builder: repeatable rows summed with an explicit AND
 * connector, list position = priority, reorderable by drag or buttons.
 *
 * Each row picks a type first (profile field or cohort). Cohorts are a
 * bounded menu on small platforms and a debounced search (backed by the
 * local_groupdist_search_cohorts web service) beyond the menu limit, so
 * thousands of cohorts are never enumerated into the page.
 *
 * The builder owns the rows and mirrors them into flattened hidden inputs
 * (affinityrulesources[i] / affinityrulemodes[i]) that the server reads via
 * options::rules_from_post() — no JSON blob travels in the POST.
 *
 * @module     local_groupdist/rules
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString} from 'core/str';

const SELECTORS = {
    REGION: '[data-region="local-groupdist-rules"]',
    ROWS: '[data-region="rows"]',
    INPUTS: '[data-region="inputs"]',
    RULE: '[data-region="rule"]',
    ADD: '[data-action="addrule"]',
    KIND: '[data-action="kind"]',
    SOURCE: '[data-action="source"]',
    MODE: '[data-action="mode"]',
    COHORTSEARCH: '[data-action="cohortsearch"]',
    COHORTRESULTS: '[data-region="cohortresults"]',
};

const SEARCHDELAY = 300;

const state = {
    root: null,
    fields: [],
    cohorts: [],
    cohortsearch: false,
    courseid: 0,
    maxrules: 10,
    rules: [],
    dragindex: null,
    searchtimer: null,
};

/**
 * Mirror the rules into the flattened hidden inputs the server reads.
 *
 * Rows without a chosen source are skipped — they are drafts, not rules.
 */
const syncInputs = () => {
    const region = state.root.querySelector(SELECTORS.INPUTS);
    region.textContent = '';
    let index = 0;
    state.rules.forEach((rule) => {
        if (rule.source === '') {
            return;
        }
        const source = document.createElement('input');
        source.type = 'hidden';
        source.name = 'affinityrulesources[' + index + ']';
        source.value = rule.source;
        const mode = document.createElement('input');
        mode.type = 'hidden';
        mode.name = 'affinityrulemodes[' + index + ']';
        mode.value = rule.mode;
        region.append(source, mode);
        index++;
    });
};

/**
 * Move a rule to another position and re-render.
 *
 * @param {Number} from Source index.
 * @param {Number} to Target index.
 */
const move = (from, to) => {
    if (to < 0 || to >= state.rules.length || from === to) {
        return;
    }
    const [rule] = state.rules.splice(from, 1);
    state.rules.splice(to, 0, rule);
    render();
};

/**
 * Render the cohort suggestion list for one search row.
 *
 * @param {Element} row The row element.
 * @param {Number} index The rule index the row represents.
 * @param {String} query The search text.
 * @returns {Promise<void>}
 */
const searchCohorts = async(row, index, query) => {
    const results = row.querySelector(SELECTORS.COHORTRESULTS);
    let matches = [];
    try {
        const response = await Ajax.call([{
            methodname: 'local_groupdist_search_cohorts',
            args: {courseid: state.courseid, query},
        }])[0];
        matches = response.cohorts;
    } catch (error) {
        Notification.exception(error);
        return;
    }

    results.textContent = '';
    if (!matches.length) {
        const empty = document.createElement('span');
        empty.className = 'list-group-item small text-muted';
        empty.textContent = await getString('rulesearchnoresults', 'local_groupdist');
        results.appendChild(empty);
    }
    matches.forEach((match) => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'list-group-item list-group-item-action small';
        option.setAttribute('role', 'option');
        option.textContent = match.label;
        option.addEventListener('click', () => {
            state.rules[index].source = match.value;
            state.rules[index].label = match.label;
            render();
        });
        results.appendChild(option);
    });
    results.hidden = false;
};

/**
 * Wire one rendered row's controls to the state.
 *
 * @param {Element} row The row element.
 * @param {Number} index The rule index the row represents.
 */
const wireRow = (row, index) => {
    row.querySelector(SELECTORS.KIND).addEventListener('change', (event) => {
        state.rules[index].kind = event.target.value;
        state.rules[index].source = '';
        state.rules[index].label = '';
        render();
    });
    const source = row.querySelector(SELECTORS.SOURCE);
    if (source) {
        source.addEventListener('change', (event) => {
            state.rules[index].source = event.target.value;
            render();
        });
    }
    const search = row.querySelector(SELECTORS.COHORTSEARCH);
    if (search) {
        search.addEventListener('input', (event) => {
            const query = event.target.value.trim();
            window.clearTimeout(state.searchtimer);
            state.searchtimer = window.setTimeout(() => searchCohorts(row, index, query), SEARCHDELAY);
        });
    }
    row.querySelector(SELECTORS.MODE).addEventListener('change', (event) => {
        state.rules[index].mode = event.target.value;
        render();
    });
    row.querySelector('[data-action="moveup"]').addEventListener('click', () => move(index, index - 1));
    row.querySelector('[data-action="movedown"]').addEventListener('click', () => move(index, index + 1));
    row.querySelector('[data-action="delete"]').addEventListener('click', () => {
        state.rules.splice(index, 1);
        render();
    });

    row.addEventListener('dragstart', () => {
        state.dragindex = index;
        row.classList.add('opacity-50');
    });
    row.addEventListener('dragend', () => {
        state.dragindex = null;
        render();
    });
    row.addEventListener('dragover', (event) => {
        event.preventDefault();
        row.classList.add('border-primary');
    });
    row.addEventListener('dragleave', () => {
        row.classList.remove('border-primary');
    });
    row.addEventListener('drop', (event) => {
        event.preventDefault();
        if (state.dragindex !== null) {
            move(state.dragindex, index);
        }
    });
};

/**
 * Render every row from the state and re-sync the hidden inputs.
 *
 * @returns {Promise<void>}
 */
const render = async() => {
    const pending = new Pending('local_groupdist/rules:render');
    const rows = state.root.querySelector(SELECTORS.ROWS);
    const rendered = await Promise.all(state.rules.map((rule, index) => Templates.renderForPromise(
        'local_groupdist/rules_row',
        {
            index: index + 1,
            isapart: rule.mode === 'apart',
            notfirst: index > 0,
            isfirst: index === 0,
            islast: index === state.rules.length - 1,
            iscohort: rule.kind === 'cohort',
            cohortsearch: state.cohortsearch,
            cohortlabel: (rule.kind === 'cohort' && rule.source !== '') ? rule.label : '',
            cohortquery: '',
            together: rule.mode === 'together',
            apart: rule.mode === 'apart',
            fields: state.fields.map((option) => ({
                value: option.value,
                label: option.label,
                selected: option.value === rule.source,
            })),
            cohorts: state.cohorts.map((option) => ({
                value: option.value,
                label: option.label,
                selected: option.value === rule.source,
            })),
        }
    )));

    rows.textContent = '';
    rendered.forEach(({html, js}, index) => {
        Templates.appendNodeContents(rows, html, js);
        wireRow(rows.querySelectorAll(SELECTORS.RULE)[index], index);
    });

    state.root.querySelector(SELECTORS.ADD).disabled = state.rules.length >= state.maxrules;
    syncInputs();
    pending.resolve();
};

/**
 * Derive the row type of an initial rule from its source key.
 *
 * @param {String} source The source key.
 * @returns {String} Either 'cohort' or 'field'.
 */
const kindOf = (source) => source.startsWith('cohort_') ? 'cohort' : 'field';

/**
 * Initialise the builder from its mount's data attributes.
 *
 * @returns {Promise<void>}
 */
export const init = async() => {
    state.root = document.querySelector(SELECTORS.REGION);
    if (!state.root) {
        return;
    }
    state.fields = JSON.parse(state.root.dataset.fields);
    state.cohorts = JSON.parse(state.root.dataset.cohorts);
    state.cohortsearch = state.root.dataset.cohortsearch === '1';
    state.courseid = parseInt(state.root.dataset.courseid, 10);
    state.maxrules = parseInt(state.root.dataset.maxrules, 10) || 10;
    state.rules = JSON.parse(state.root.dataset.rules).map((rule) => ({
        kind: kindOf(rule.source),
        source: rule.source,
        mode: rule.mode,
        label: rule.label || '',
    }));

    state.root.querySelector(SELECTORS.ADD).addEventListener('click', () => {
        state.rules.push({kind: 'field', source: '', mode: 'together', label: ''});
        render();
    });
    await render();
};
