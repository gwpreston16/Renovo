/**
 * The subscriptions list's bulk selection.
 *
 * The bulk bar is drawn open by the server, so a browser with no script can
 * still tick rows and apply an action. With script it stays out of the way
 * until something is selected, says how many, and a header checkbox selects
 * the whole page. The list is replaced by htmx on every filter, sort and page,
 * so this listens on the document rather than binding to rows that are about
 * to be swapped away.
 */

const BAR = '[data-bulk-bar]';
const ROW = 'input[data-bulk-select]';
const ALL = 'input[data-select-all]';

function update(list) {
    const bar = list.querySelector(BAR);
    if (bar === null) {
        return;
    }

    const rows = [...list.querySelectorAll(ROW)];
    const chosen = rows.filter((row) => row.checked).length;

    bar.hidden = chosen === 0;

    const count = bar.querySelector('[data-bulk-count]');
    if (count !== null && chosen > 0) {
        count.textContent = (count.dataset.countLabel ?? '%d').replace('%d', String(chosen));
    }

    const all = list.querySelector(ALL);
    if (all !== null) {
        all.checked = chosen > 0 && chosen === rows.length;
        all.indeterminate = chosen > 0 && chosen < rows.length;
    }
}

/**
 * @param {ParentNode} [root] Where to look; the whole document by default.
 */
export function enhanceSubscriptionLists(root = document) {
    const lists = root instanceof Element && root.matches('#subscription-list')
        ? [root]
        : [...root.querySelectorAll('#subscription-list')];

    lists.forEach((list) => {
        list.querySelectorAll(ALL).forEach((all) => {
            all.hidden = false;
        });
        update(list);
    });
}

document.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) {
        return;
    }

    const list = target.closest('#subscription-list');
    if (list === null) {
        return;
    }

    if (target.matches(ALL)) {
        list.querySelectorAll(ROW).forEach((row) => {
            row.checked = target.checked;
        });
    }

    if (target.matches(ALL) || target.matches(ROW)) {
        update(list);
    }
});

/*
 * The toolbar's Filters button, for a phone (the stylesheet hides it from
 * 768px up and folds nothing there).
 *
 * The server draws it hidden, so a browser with no script keeps every filter
 * open. With script it appears, and the chips, segments and tools fold behind
 * it when no filter is on: a list someone has narrowed opens with the reason
 * showing. The fold is a class on the toolbar, which htmx never replaces, so
 * it survives every out-of-band swap of `#list-filters` and `#list-tools`.
 */
const TOOLBAR = '.subscriptions-toolbar';
const TOGGLE = '[data-filters-toggle]';

/* The chosen link of each group when nothing narrows the list. */
const UNFILTERED = new Set(['filter-category-all', 'filter-status-all', 'filter-scope-household']);

/**
 * How many filters are narrowing the list: the chosen chips and segments,
 * other than each group's "everything" choice. Read from `aria-current`,
 * which the server sets, so it is right after every swap.
 *
 * @param {Element} toolbar
 * @returns {number}
 */
export function countChosenFilters(toolbar) {
    return [...toolbar.querySelectorAll('#list-filters [aria-current="true"]')]
        .filter((link) => !UNFILTERED.has(link.id))
        .length;
}

function setFolded(toolbar, toggle, folded) {
    toolbar.classList.toggle('is-folded', folded);
    toggle.setAttribute('aria-expanded', folded ? 'false' : 'true');
}

/**
 * Shows the Filters button, folds the filters the first time (when none is
 * on), and refreshes the count. Safe to call again after every swap.
 *
 * @param {ParentNode} [root] Where to look; the whole document by default.
 */
export function enhanceFilterToggles(root = document) {
    root.querySelectorAll(TOOLBAR).forEach((toolbar) => {
        const toggle = toolbar.querySelector(TOGGLE);
        if (toggle === null) {
            return;
        }

        const chosen = countChosenFilters(toolbar);

        if (toggle.hidden) {
            toggle.hidden = false;
            setFolded(toolbar, toggle, chosen === 0);
        }

        const count = toggle.querySelector('[data-filters-count]');
        if (count !== null) {
            count.hidden = chosen === 0;
            count.textContent = chosen === 0 ? '' : String(chosen);
        }

        nameToggle(toggle, chosen);
    });
}

/*
 * The count alone is read as "Filters 2", a bare number, so while filters are
 * on the button is named for what it counts ("Filters, 2 on", K242). With none
 * on the name goes, and the button is read by its visible label. Without the
 * catalogue string the name is left alone rather than set to nothing.
 */
function nameToggle(toggle, chosen) {
    const template = (window.renovoI18n || {}).filters_on;
    if (chosen === 0 || !template) {
        toggle.removeAttribute('aria-label');

        return;
    }

    toggle.setAttribute('aria-label', template.replace('{count}', () => String(chosen)));
}

document.addEventListener('click', (event) => {
    const toggle = event.target instanceof Element ? event.target.closest(TOGGLE) : null;
    if (toggle === null) {
        return;
    }

    const toolbar = toggle.closest(TOOLBAR);
    if (toolbar !== null) {
        setFolded(toolbar, toggle, !toolbar.classList.contains('is-folded'));
    }
});
