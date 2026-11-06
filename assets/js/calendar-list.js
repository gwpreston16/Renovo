/**
 * The calendar's phone list, fetched when a phone needs it (K184).
 *
 * The month page carries the grid only: the list is the same days again, and
 * a desktop reader would download a list their screen hides. Below 768px the
 * month leaves a `data-calendar-list` container holding a "Show as list" link,
 * and this puts the list from that URL in its place, after the first load and
 * after each month or day swap (a swap brings a fresh container). The server
 * builds the list from the same calendar service and scope as the grid.
 *
 * Without script, or if the request fails, the link stays: it opens the same
 * month with the list in place of the grid, so no one loses the list.
 */

/** The phone layout's media query: the same breakpoint the stylesheet switches on. */
const NARROW = '(max-width: 767px)';

let listening = false;

function isNarrow() {
    return typeof window.matchMedia === 'function' && window.matchMedia(NARROW).matches;
}

/**
 * Fetch one container's list and put it in place. A container is asked for
 * once; one that failed goes back to its link and may be asked for again.
 */
async function load(container) {
    if (container.dataset.listState !== undefined) {
        return;
    }
    container.dataset.listState = 'loading';

    try {
        const response = await fetch(container.getAttribute('data-calendar-list'), {
            credentials: 'same-origin',
        });
        if (!response.ok) {
            throw new Error(`The list answered ${response.status}.`);
        }
        const html = await response.text();
        // A swap may have replaced the month while the request was out.
        if (container.isConnected) {
            container.innerHTML = html;
            container.dataset.listState = 'loaded';
        }
    } catch {
        delete container.dataset.listState;
    }
}

/**
 * Load the list into every calendar container under `root`, on a phone only.
 *
 * Also wakes when the window narrows to a phone's width, so a window dragged
 * smaller gets its list without a reload.
 */
export function enhanceCalendarLists(root = document) {
    if (!listening && typeof window.matchMedia === 'function') {
        listening = true;
        window.matchMedia(NARROW).addEventListener('change', () => enhanceCalendarLists(document));
    }

    if (!isNarrow()) {
        return;
    }
    root.querySelectorAll('[data-calendar-list]').forEach(load);
}
