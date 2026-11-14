/**
 * Customising the dashboard: dragging a card by its handle, and the arrows
 * moving it without a reload.
 *
 * The page works without this. Each card's controls are a form whose arrows
 * and Show/Hide post to `/dashboard/layout` and come back to the card; what
 * this adds is the drag, and saving a move in place. Show and Hide are left
 * to their forms, because showing a hidden card needs content the server did
 * not draw.
 *
 * SortableJS is behind a dynamic `import()`, like Chart.js in `charts.js`:
 * only the customising page needs it, so every other page loads nothing.
 *
 * A move is shown at once and saved after. Saves go one at a time, each
 * sending the order as it stands when its turn comes, so two quick moves can
 * neither overlap on the server nor land in the wrong order. If a save fails,
 * the grid goes back to the last order the server accepted and the status
 * line says so.
 */

let library;

function loadSortable() {
    library ??= import('sortablejs').then(({ default: Sortable }) => Sortable);

    return library;
}

function items(grid) {
    return Array.from(grid.querySelectorAll(':scope > [data-card]'));
}

function order(grid) {
    return items(grid).map((item) => item.dataset.card);
}

function restore(grid, keys) {
    const byKey = new Map(items(grid).map((item) => [item.dataset.card, item]));
    keys.forEach((key) => {
        const item = byKey.get(key);
        if (item) {
            grid.append(item);
        }
    });
}

/*
 * The first card cannot go up and the last cannot go down. The server draws
 * this for the order it knows; after a move in place it is redrawn here.
 */
function refreshButtons(grid) {
    const all = items(grid);
    all.forEach((item, index) => {
        const up = item.querySelector('[data-card-move="-1"]');
        const down = item.querySelector('[data-card-move="1"]');
        if (up) {
            up.disabled = index === 0;
        }
        if (down) {
            down.disabled = index === all.length - 1;
        }
    });
}

function say(text) {
    const status = document.querySelector('[data-card-layout-status]');
    if (status) {
        status.textContent = text;
    }
}

function announceMove(grid, item) {
    const template = grid.dataset.movedMessage || '';
    const position = items(grid).indexOf(item) + 1;

    // Replaced with functions, so a name is inserted as written: a string
    // replacement would read `$&` in it as a pattern.
    say(template
        .replace('{position}', () => String(position))
        .replace('{card}', () => item.dataset.cardName || ''));
}

/*
 * The token is the one in the card's own form, and the request carries the
 * htmx header so the server answers with an empty 204 rather than a redirect
 * that would reload the page this has just rearranged.
 */
async function post(grid, keys) {
    const token = grid.querySelector('form.card-controls input[name="_csrf"]')?.value || '';
    const body = new FormData();
    body.append('_csrf', token);
    body.append('view', grid.dataset.view || '');
    keys.forEach((key) => body.append('order[]', key));

    const response = await fetch('/dashboard/layout', {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { 'HX-Request': 'true', 'X-CSRF-Token': token },
    });
    if (!response.ok) {
        throw new Error(`layout save answered ${response.status}`);
    }
}

function saver(grid) {
    let saved = order(grid);
    let queue = Promise.resolve();

    return () => {
        queue = queue.then(async () => {
            const keys = order(grid);
            try {
                await post(grid, keys);
                saved = keys;
            } catch {
                restore(grid, saved);
                refreshButtons(grid);
                say(grid.dataset.failedMessage || '');
            }
        });
    };
}

/*
 * How long the other cards take to slide aside while one is dragged: none
 * for a member whose system asks for less motion. Sortable animates with an
 * inline transition, which base.css's reduced-motion rule cannot reach, and
 * base.css says every movement must answer `prefers-reduced-motion` (K243).
 * Read when the grid is set up, as the drag starts long after.
 */
function dragAnimation() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 0 : 150;
}

function enhanceGrid(grid, Sortable) {
    if (grid.dataset.cardLayoutReady) {
        return;
    }
    grid.dataset.cardLayoutReady = 'true';

    grid.querySelectorAll('[data-card-drag]').forEach((handle) => {
        handle.hidden = false;
    });

    const save = saver(grid);

    Sortable.create(grid, {
        handle: '[data-card-drag]',
        draggable: '[data-card]',
        animation: dragAnimation(),
        ghostClass: 'is-drag-ghost',
        chosenClass: 'is-drag-chosen',
        // A finger on a phone is scrolling until it has held still a moment;
        // a mouse drags at once.
        delay: 200,
        delayOnTouchOnly: true,
        // Sortable's own pointer handling rather than the browser's drag and
        // drop, which Firefox will not start from inside a <button>.
        forceFallback: true,
        onEnd(event) {
            if (event.oldIndex === event.newIndex) {
                return;
            }
            refreshButtons(grid);
            announceMove(grid, event.item);
            save();
        },
    });

    grid.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-card-move]') : null;
        if (!button || button.disabled) {
            return;
        }
        event.preventDefault();

        const item = button.closest('[data-card]');
        if (button.dataset.cardMove === '-1' && item.previousElementSibling) {
            grid.insertBefore(item, item.previousElementSibling);
        } else if (button.dataset.cardMove === '1' && item.nextElementSibling) {
            grid.insertBefore(item.nextElementSibling, item);
        } else {
            return;
        }

        refreshButtons(grid);
        // Keep the keyboard where it was: on the same button of the card that
        // moved, or on the other arrow if this one has just been disabled.
        const sibling = item.querySelector(`[data-card-move="${button.dataset.cardMove === '-1' ? '1' : '-1'}"]`);
        (button.disabled ? sibling : button)?.focus();
        announceMove(grid, item);
        save();
    });
}

export function enhanceCardLayouts(root = document) {
    const grids = Array.from(root.querySelectorAll('[data-card-layout]'));
    if (grids.length === 0) {
        return Promise.resolve();
    }

    return loadSortable().then((Sortable) => {
        grids.forEach((grid) => enhanceGrid(grid, Sortable));
    });
}
