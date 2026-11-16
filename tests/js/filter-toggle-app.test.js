import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { htmx, loadApp, unloadApp } from './load-app.js';

/*
 * K248: the phone Filters toggle as `app.js` wires it. Each test fails if one
 * piece of that wiring goes — the call in `hydrate()`, the call in
 * `enhanceArrived`, and the "fold only the first time" check in
 * `enhanceFilterToggles`.
 *
 * A file of its own, apart from filter-toggle.test.js: that file imports
 * `subscription-list.js` directly, and `loadApp()` loads a fresh copy, so the
 * two copies' document click handlers would both answer one click and fold
 * the filters straight back.
 */

function toolbar(chosenIds) {
    const link = (id) => `<a id="${id}"${chosenIds.includes(id) ? ' aria-current="true"' : ''}>x</a>`;

    return '<section class="subscriptions-toolbar">'
        + '<div class="list-search-bar">'
        + '<button type="button" data-filters-toggle aria-expanded="true" hidden>'
        + '<span>Filters</span><span data-filters-count hidden></span></button></div>'
        + '<div id="list-filters" class="list-filters">'
        + link('filter-category-all') + link('filter-category-5')
        + link('filter-tag-2')
        + link('filter-status-all') + link('filter-status-paused')
        + '</div><div id="list-tools" class="list-tools"></div></section>';
}

const bar = () => document.querySelector('.subscriptions-toolbar');
const toggle = () => document.querySelector('[data-filters-toggle]');
const count = () => document.querySelector('[data-filters-count]');

/** What the list's out-of-band swap does: `#list-filters` replaced whole. */
function swapFilters(chosenIds) {
    const fresh = document.createElement('div');
    fresh.innerHTML = toolbar(chosenIds);
    document.getElementById('list-filters').replaceWith(fresh.querySelector('#list-filters'));
    htmx('afterSwap', document.getElementById('list-filters'));
}

beforeEach(() => {
    // A phone-sized window, as the other phone-only enhancements app.js runs
    // read it.
    window.matchMedia = vi.fn(() => ({
        matches: true,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }));
});

afterEach(() => {
    unloadApp();
    vi.restoreAllMocks();
});

test('app.js shows the Filters button on load', async () => {
    document.body.innerHTML = toolbar([]);
    await loadApp();

    expect(toggle().hidden).toBe(false);
    expect(bar().classList.contains('is-folded')).toBe(true);
});

test('app.js updates the count after a swap', async () => {
    document.body.innerHTML = toolbar(['filter-tag-2']);
    await loadApp();
    expect(count().textContent).toBe('1');

    swapFilters(['filter-category-5', 'filter-tag-2', 'filter-status-paused']);

    expect(count().textContent).toBe('3');
});

test('an opened toolbar stays open across a swap with the same count', async () => {
    document.body.innerHTML = toolbar([]);
    await loadApp();
    toggle().click();
    expect(bar().classList.contains('is-folded')).toBe(false);

    // Still no filter on: a check that folded on every pass would fold again.
    swapFilters([]);

    expect(bar().classList.contains('is-folded')).toBe(false);
    expect(toggle().getAttribute('aria-expanded')).toBe('true');
});
