import { afterEach, beforeEach, expect, test } from 'vitest';
import { countChosenFilters, enhanceFilterToggles } from '../../assets/js/subscription-list.js';

/*
 * The subscriptions toolbar's Filters button on a phone: drawn hidden by the
 * server, shown by script, folding the filters only when none is on, and
 * keeping its count right after htmx redraws `#list-filters`.
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
        + link('filter-scope-household') + link('filter-scope-mine')
        + '</div><div id="list-tools" class="list-tools"></div></section>';
}

const bar = () => document.querySelector('.subscriptions-toolbar');
const toggle = () => document.querySelector('[data-filters-toggle]');
const count = () => document.querySelector('[data-filters-count]');

beforeEach(() => {
    document.body.innerHTML = '';
    window.renovoI18n = i18n({ filters_on: 'Filters, {count} on' });
});

afterEach(() => {
    delete window.renovoI18n;
});

/*
 * What public/assets/i18n.js puts on the window: t() hands back the key for a
 * string the catalogue lacks.
 */
function i18n(messages) {
    return {
        t: (key, params = {}) => Object.entries(params)
            .reduce((message, [name, value]) => message.split(`{${name}}`).join(value), messages[key] || key),
    };
}

/*
 * jsdom computes no accessible name, so this is the rule it follows: an
 * aria-label wins, else the button's text with the hidden count left out.
 */
function accessibleName(button) {
    return button.getAttribute('aria-label')
        ?? button.querySelector('span:not([hidden])').textContent;
}

test('each group\'s "everything" choice is not counted as a filter', () => {
    document.body.innerHTML = toolbar(['filter-category-all', 'filter-status-all', 'filter-scope-household']);

    expect(countChosenFilters(bar())).toBe(0);
});

test('a chosen category, tag, status and scope are each counted', () => {
    document.body.innerHTML = toolbar(['filter-category-5', 'filter-tag-2', 'filter-status-paused', 'filter-scope-mine']);

    expect(countChosenFilters(bar())).toBe(4);
});

test('with no filter on, the button appears and the filters fold', () => {
    document.body.innerHTML = toolbar(['filter-category-all', 'filter-status-all', 'filter-scope-household']);
    enhanceFilterToggles(document);

    expect(toggle().hidden).toBe(false);
    expect(bar().classList.contains('is-folded')).toBe(true);
    expect(toggle().getAttribute('aria-expanded')).toBe('false');
    expect(count().hidden).toBe(true);
    expect(count().textContent).toBe('');
});

test('with a filter on, the list opens with the reason showing, and says how many', () => {
    document.body.innerHTML = toolbar(['filter-category-5', 'filter-status-all', 'filter-scope-household']);
    enhanceFilterToggles(document);

    expect(bar().classList.contains('is-folded')).toBe(false);
    expect(toggle().getAttribute('aria-expanded')).toBe('true');
    expect(count().hidden).toBe(false);
    expect(count().textContent).toBe('1');
});

test('the button folds and unfolds the filters', () => {
    document.body.innerHTML = toolbar([]);
    enhanceFilterToggles(document);

    toggle().click();
    expect(bar().classList.contains('is-folded')).toBe(false);
    expect(toggle().getAttribute('aria-expanded')).toBe('true');

    toggle().click();
    expect(bar().classList.contains('is-folded')).toBe(true);
    expect(toggle().getAttribute('aria-expanded')).toBe('false');
});

test('after a swap redraws the filters, the count follows and the fold is left as the reader set it', () => {
    document.body.innerHTML = toolbar([]);
    enhanceFilterToggles(document);
    toggle().click();

    // What an out-of-band swap does: `#list-filters` replaced whole.
    const fresh = document.createElement('div');
    fresh.innerHTML = toolbar(['filter-category-5', 'filter-tag-2']);
    document.getElementById('list-filters').replaceWith(fresh.querySelector('#list-filters'));
    enhanceFilterToggles(document);

    expect(bar().classList.contains('is-folded')).toBe(false);
    expect(count().textContent).toBe('2');
});

test('a page without the toolbar is left alone', () => {
    document.body.innerHTML = '<main><p>Elsewhere</p></main>';

    expect(() => enhanceFilterToggles(document)).not.toThrow();
});

test('the Filters button says how many are on', () => {
    document.body.innerHTML = toolbar(['filter-category-5', 'filter-tag-2']);
    enhanceFilterToggles(document);
    expect(accessibleName(toggle())).toBe('Filters, 2 on');
    // The count stays visible as it was.
    expect(count().textContent).toBe('2');

    // A swap to one filter on.
    const fresh = document.createElement('div');
    fresh.innerHTML = toolbar(['filter-tag-2']);
    document.getElementById('list-filters').replaceWith(fresh.querySelector('#list-filters'));
    enhanceFilterToggles(document);
    expect(accessibleName(toggle())).toBe('Filters, 1 on');

    // And to none: the name goes, and the button is read by its label.
    fresh.innerHTML = toolbar([]);
    document.getElementById('list-filters').replaceWith(fresh.querySelector('#list-filters'));
    enhanceFilterToggles(document);
    expect(toggle().hasAttribute('aria-label')).toBe(false);
    expect(accessibleName(toggle())).toBe('Filters');
});

test('without the catalogue string, or without i18n.js, the button keeps its own name', () => {
    window.renovoI18n = i18n({});
    document.body.innerHTML = toolbar(['filter-category-5']);
    enhanceFilterToggles(document);
    expect(toggle().hasAttribute('aria-label')).toBe(false);

    delete window.renovoI18n;
    enhanceFilterToggles(document);
    expect(toggle().hasAttribute('aria-label')).toBe(false);
});
