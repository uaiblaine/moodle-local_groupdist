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
 * The builder owns the rows and mirrors them into flattened hidden inputs
 * (affinityrulesources[i] / affinityrulemodes[i]) that the server reads via
 * options::rules_from_post() — no JSON blob travels in the POST.
 *
 * @module     local_groupdist/rules
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import Templates from 'core/templates';

const SELECTORS = {
    REGION: '[data-region="local-groupdist-rules"]',
    ROWS: '[data-region="rows"]',
    INPUTS: '[data-region="inputs"]',
    RULE: '[data-region="rule"]',
    ADD: '[data-action="addrule"]',
    SOURCE: '[data-action="source"]',
    MODE: '[data-action="mode"]',
};

const state = {
    root: null,
    sources: [],
    maxrules: 10,
    rules: [],
    dragindex: null,
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
 * Wire one rendered row's controls to the state.
 *
 * @param {Element} row The row element.
 * @param {Number} index The rule index the row represents.
 */
const wireRow = (row, index) => {
    row.querySelector(SELECTORS.SOURCE).addEventListener('change', (event) => {
        state.rules[index].source = event.target.value;
        render();
    });
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
            together: rule.mode === 'together',
            apart: rule.mode === 'apart',
            sources: state.sources.map((option) => ({
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
 * Initialise the builder from its mount's data attributes.
 *
 * @returns {Promise<void>}
 */
export const init = async() => {
    state.root = document.querySelector(SELECTORS.REGION);
    if (!state.root) {
        return;
    }
    state.sources = JSON.parse(state.root.dataset.sources);
    state.rules = JSON.parse(state.root.dataset.rules);
    state.maxrules = parseInt(state.root.dataset.maxrules, 10) || 10;

    state.root.querySelector(SELECTORS.ADD).addEventListener('click', () => {
        state.rules.push({source: '', mode: 'together'});
        render();
    });
    await render();
};
