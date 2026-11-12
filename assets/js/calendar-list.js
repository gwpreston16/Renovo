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
 *
 * The request says it is htmx's (`HX-Request`), so an ended session or a
 * pending password change is answered with `HX-Redirect`, and the whole page
 * goes there rather than the sign-in page being drawn inside the card (K232).
 * A history snapshot never keeps a list "loading" (K231): Back restores the
 * link, and a phone asks for the list again.
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
    showLoading(container, true);

    try {
        const response = await fetch(container.getAttribute('data-calendar-list'), {
            credentials: 'same-origin',
            headers: {'HX-Request': 'true'},
        });
        // Checked before `ok`: the middlewares' answer is a 204, which is ok
        // and empty, and would otherwise be written in as the list.
        const redirect = response.headers.get('HX-Redirect');
        if (redirect) {
            window.location.assign(redirect);
            return;
        }
        if (!response.ok) {
            throw new Error(`The list answered ${response.status}.`);
        }
        const html = await response.text();
        // A swap may have replaced the month while the request was out, or a
        // history snapshot put the container back to its link (and a fresh
        // request may follow): either way this answer is no longer wanted.
        if (container.isConnected && container.dataset.listState === 'loading') {
            container.innerHTML = html;
            showLoading(container, false);
            container.dataset.listState = 'loaded';
        }
    } catch {
        delete container.dataset.listState;
        showLoading(container, false);
    }
}

/**
 * While the list loads, a "Loading the list…" line stands where the link was
 * and the container is marked busy. A reader is never offered a button that
 * is about to vanish under their finger. A failure puts the link back.
 *
 * The line is a status just before the container, outside the busy region
 * whose contents the list replaces. It stays in the page (and so in the
 * accessibility tree) and only its text changes: a live region is read out
 * when its text changes, not reliably when it appears (K233). The text comes
 * from the status's `data-loading-text`; empty, the stylesheet folds it away.
 */
function showLoading(container, loading) {
    const link = container.querySelector('.calendar-list-link');
    const status = container.previousElementSibling;
    if (link) {
        link.hidden = loading;
    }
    if (status instanceof HTMLElement && status.matches('.calendar-list-loading')) {
        status.textContent = loading ? (status.dataset.loadingText || '') : '';
    }
    if (loading) {
        container.setAttribute('aria-busy', 'true');
    } else {
        container.removeAttribute('aria-busy');
    }
}

/**
 * Put every list still loading back to its link before htmx saves a history
 * snapshot (K231), as a reload would draw it. A snapshot taken mid-request
 * would otherwise come back on Back as "Loading the list…" with no request
 * behind it. The request still out is dropped when it answers (see `load()`).
 */
export function resetLoadingListsForHistory() {
    document.querySelectorAll('[data-calendar-list][data-list-state="loading"]').forEach((container) => {
        delete container.dataset.listState;
        showLoading(container, false);
    });
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
