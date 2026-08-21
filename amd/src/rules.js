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
 * Each row picks a type first (profile field, cohort or course group).
 * Cohorts are a bounded menu on small platforms and a debounced search
 * (local_groupdist_search_cohorts) beyond the menu limit, so thousands of
 * cohorts are never enumerated into the page. Course groups follow the same
 * two-mode shape (local_groupdist_search_groups), but for a different reason:
 * they are course-bounded and the server already holds the whole list, so the
 * bound is about a usable picker rather than about disclosure.
 *
 * A group this run distributes INTO is offered but disabled while the "ignore
 * users already in the selected groups" checkbox is ticked: that filter
 * removes every user who could carry the value, so such a rule would match
 * nobody. The checkbox is watched live, because it can be unticked after a
 * rule was picked.
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
    SOURCESEARCH: '[data-action="sourcesearch"]',
    SOURCERESULTS: '[data-region="sourceresults"]',
    IGNOREGROUPED: '[name="ignoregrouped"]',
};

/** @var {Object} Search web service per source kind. */
const SEARCHMETHOD = {
    cohort: {method: 'local_groupdist_search_cohorts', key: 'cohorts', empty: 'rulesearchnoresults'},
    group: {method: 'local_groupdist_search_groups', key: 'groups', empty: 'rulesearchnogroups'},
};

const SEARCHDELAY = 300;

/* The placeholder handed to getString() once at init, then swapped for each
   group's own name at render time. Prefetching this way lets optionsFor() stay
   synchronous while the WHOLE option label — word order and punctuation
   included — stays in the language pack: a translation is free to put the
   marker before the name, because the name is substituted INTO the string
   rather than the string being appended to the name. */
const NAMETOKEN = '%%name%%';

const state = {
    root: null,
    fields: [],
    cohorts: [],
    cohortsearch: false,
    groups: [],
    groupsearch: false,
    destinations: [],
    destinationstrings: {blocked: '', also: ''},
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
 * Whether a source key names one of the groups this run distributes into.
 *
 * @param {String} source The source key.
 * @returns {Boolean} True when it is a destination of this run.
 */
const isDestination = (source) => state.destinations.indexOf(source) !== -1;

/**
 * Whether the ignore-grouped filter is currently ticked.
 *
 * Read from the live checkbox rather than cached, because unticking it makes
 * a destination group a usable source again without any page reload.
 *
 * @returns {Boolean} True when the filter is on.
 */
const ignoreGrouped = () => {
    const checkbox = document.querySelector(SELECTORS.IGNOREGROUPED);
    return checkbox ? checkbox.checked : true;
};

/**
 * Whether a source key is a destination that cannot constrain anything.
 *
 * @param {String} source The source key.
 * @returns {Boolean} True when the option must be disabled.
 */
const isBlockedDestination = (source) => isDestination(source) && ignoreGrouped();

/**
 * The option label for one source, marked when it is a destination of this run.
 *
 * @param {String} source The source key.
 * @param {String} name The group's own name.
 * @returns {String} The label to show.
 */
const optionLabel = (source, name) => {
    if (!isDestination(source)) {
        return name;
    }
    const template = ignoreGrouped() ? state.destinationstrings.blocked : state.destinationstrings.also;
    return template.replace(NAMETOKEN, name);
};

/**
 * Whether the picker for a source kind is a search box rather than a menu.
 *
 * @param {String} kind The row kind.
 * @returns {Boolean} True in search mode.
 */
const searchModeFor = (kind) => {
    if (kind === 'cohort') {
        return state.cohortsearch;
    }
    if (kind === 'group') {
        return state.groupsearch;
    }
    return false;
};

/**
 * The select options for one row, decorated for the row's kind.
 *
 * @param {Object} rule The rule the row represents.
 * @returns {Array} Option objects for the template.
 */
const optionsFor = (rule) => {
    let source = state.fields;
    if (rule.kind === 'cohort') {
        source = state.cohorts;
    } else if (rule.kind === 'group') {
        source = state.groups;
    }
    return source.map((option) => ({
        value: option.value,
        label: optionLabel(option.value, option.label),
        selected: option.value === rule.source,
        /* A destination group stays VISIBLE and disabled rather than being
           dropped: someone looking for "Group 02" has to find it and read why
           it cannot be used, or the picker just looks broken. */
        disabled: isBlockedDestination(option.value) && option.value !== rule.source,
    }));
};

/**
 * Render the suggestion list for one search row, whichever kind it is.
 *
 * @param {Element} row The row element.
 * @param {Number} index The rule index the row represents.
 * @param {String} kind The row kind captured when the search was scheduled.
 * @param {String} query The search text.
 * @returns {Promise<void>}
 */
const searchSources = async(row, index, kind, query) => {
    /* The kind is captured when the keystroke is scheduled, not read here: by
       the time the debounce fires, the row may have been deleted (state.rules
       [index] undefined) or switched to another type, and resolving it now
       would throw or query the other kind's service. A row that is gone or has
       changed kind simply drops its stale result. */
    const search = SEARCHMETHOD[kind];
    if (!search || !state.rules[index] || state.rules[index].kind !== kind) {
        return;
    }
    const results = row.querySelector(SELECTORS.SOURCERESULTS);
    if (!results) {
        return;
    }
    let matches = [];
    try {
        const response = await Ajax.call([{
            methodname: search.method,
            args: {courseid: state.courseid, query},
        }])[0];
        matches = response[search.key];
    } catch (error) {
        Notification.exception(error);
        return;
    }

    results.textContent = '';
    if (!matches.length) {
        const empty = document.createElement('span');
        empty.className = 'list-group-item small text-muted';
        empty.textContent = await getString(search.empty, 'local_groupdist');
        results.appendChild(empty);
    }
    matches.forEach((match) => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'list-group-item list-group-item-action small';
        option.setAttribute('role', 'option');
        const blocked = isBlockedDestination(match.value);
        option.disabled = blocked;
        option.textContent = optionLabel(match.value, match.label);
        option.addEventListener('click', () => {
            if (blocked || !state.rules[index] || state.rules[index].kind !== kind) {
                return;
            }
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
    const search = row.querySelector(SELECTORS.SOURCESEARCH);
    if (search) {
        const kind = state.rules[index].kind;
        search.addEventListener('input', (event) => {
            const query = event.target.value.trim();
            window.clearTimeout(state.searchtimer);
            state.searchtimer = window.setTimeout(() => searchSources(row, index, kind, query), SEARCHDELAY);
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
            isfield: rule.kind === 'field',
            iscohort: rule.kind === 'cohort',
            isgroup: rule.kind === 'group',
            searchmode: searchModeFor(rule.kind),
            chosenlabel: (searchModeFor(rule.kind) && rule.source !== '') ? rule.label : '',
            query: '',
            together: rule.mode === 'together',
            apart: rule.mode === 'apart',
            options: optionsFor(rule),
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
 * A key this does not recognise falls back to 'field', which renders a select
 * that does not contain it — so every source encoding the server accepts must
 * appear here, or a stored rule silently loses its source on the first
 * re-render.
 *
 * @param {String} source The source key.
 * @returns {String} One of 'cohort', 'group' or 'field'.
 */
const kindOf = (source) => {
    if (source.startsWith('cohort_')) {
        return 'cohort';
    }
    if (source.startsWith('group_')) {
        return 'group';
    }
    return 'field';
};

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
    state.groups = JSON.parse(state.root.dataset.groups);
    state.groupsearch = state.root.dataset.groupsearch === '1';
    state.destinations = JSON.parse(state.root.dataset.destinations).map((id) => 'group_' + id);
    state.courseid = parseInt(state.root.dataset.courseid, 10);
    state.maxrules = parseInt(state.root.dataset.maxrules, 10) || 10;
    state.rules = JSON.parse(state.root.dataset.rules).map((rule) => ({
        kind: kindOf(rule.source),
        source: rule.source,
        mode: rule.mode,
        label: rule.label || '',
    }));

    const [blocked, also] = await Promise.all([
        getString('rulegroupdestinationblocked', 'local_groupdist', NAMETOKEN),
        getString('rulegroupdestinationalso', 'local_groupdist', NAMETOKEN),
    ]);
    state.destinationstrings = {blocked, also};

    state.root.querySelector(SELECTORS.ADD).addEventListener('click', () => {
        state.rules.push({kind: 'field', source: '', mode: 'together', label: ''});
        render();
    });
    /* The ignore-grouped filter decides whether a destination group is a
       usable source, so the picker has to follow it rather than read it once. */
    const ignore = document.querySelector(SELECTORS.IGNOREGROUPED);
    if (ignore) {
        ignore.addEventListener('change', () => render());
    }
    await render();
};
