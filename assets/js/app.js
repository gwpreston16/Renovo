/**
 * The bundled entry point.
 *
 * This is deliberately small. The application's own behaviour — keyboard
 * shortcuts, the quick-add dialog, passkey registration — lives in
 * `public/assets/app.js` and `public/assets/webauthn.js`, which are
 * hand-written, served directly and untouched by this build. htmx is vendored
 * the same way. None of them needed bundling and so none of them were moved.
 *
 * What this file provides is what does: a chart library too large to load on
 * every page, exposed on `window.Renovo` with a helper for building an icon
 * from the sprite. (Icons used to be filled in here after the page painted;
 * since Phase 18 the server renders them, so the navigation has its pictures
 * with the script blocked.) Phase 10 added the first page that draws a chart:
 * the dashboard's twelve-month spend, from a payload the server rendered beside the canvas.
 * Phase 12 added the second kind and the first page carrying two — the
 * analytics screen's spending trajectory beside its category donut — which is
 * why the drawing below is a list rather than a call.
 *
 * Usage from a page's own script:
 *
 *     await window.Renovo.chart(canvas, { type: 'line', data: … });
 *     element.append(window.Renovo.icon('calendar'));
 */

import { enhanceAuthForms } from './auth-forms.js';
import { enhanceCardLayouts } from './card-layout.js';
import { onThemeChange, renderChart } from './charts.js';
import { drawCategoryDonuts } from './category-donut.js';
import { enhanceCopyFields } from './copy-field.js';
import { applyGeometry, stripGeometryForHistory } from './geometry.js';
import { bindFormBehaviours } from './form-behaviours.js';
import { icon } from './icons.js';
import { enhancePaymentMethodFields } from './payment-method-field.js';
import { enhanceSubscriptionForms } from './subscription-form.js';
import { enhanceSubscriptionLists } from './subscription-list.js';
import { enhanceTagFields } from './tag-field.js';

/*
 * Assigned, not merged: this is the only thing that writes window.Renovo, and
 * a second definition appearing later should be a visible conflict rather than
 * a silent half-overwrite.
 */
window.Renovo = {
    chart: renderChart,
    icon,
};

/**
 * Every kind of chart this application draws.
 *
 * The spend charts are drawn on the server as bars, so the donut is the one
 * kind left. It returns immediately on a page that has none, so a page with no
 * chart pays for one `querySelectorAll` and nothing else; Chart.js is fetched
 * only when there is something to draw with it.
 */
function drawCharts(root = document) {
    return drawCategoryDonuts(root);
}

/*
 * The enhancements, run once the document is there to enhance.
 *
 * This is a module, so it is deferred and the markup already exists by the
 * time it runs; the readyState test is for the case where it does not, which
 * is a module fetched from cache faster than the parser.
 */
function hydrate() {
    applyGeometry(document);
    bindFormBehaviours();
    enhanceAuthForms(document);
    enhanceTagFields(document);
    enhancePaymentMethodFields(document);
    enhanceSubscriptionForms(document);
    enhanceSubscriptionLists(document);
    enhanceCopyFields(document);
    enhanceCardLayouts(document);
    drawCharts();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrate);
} else {
    hydrate();
}

/*
 * Content that arrived after this module first ran: an htmx swap, or the
 * quick-add dialog's form, which `public/assets/app.js` inserts itself and
 * announces with `renovo:content-loaded`.
 */
function enhanceArrived(event) {
    const root = event.target instanceof Element ? event.target : document;

    // The whole document, for the same reason as the list below: an outerHTML
    // swap's target is the element it replaced, no longer in the page.
    applyGeometry(document);

    enhanceTagFields(root);
    enhancePaymentMethodFields(root);
    enhanceSubscriptionForms(root);
    enhanceCardLayouts(root);
    // The list fragment replaces `#subscription-list` whole, so its bulk bar
    // arrives drawn open again and needs hiding until something is chosen.
    // The whole document rather than the event's target: an outerHTML swap's
    // target is the element that was just replaced.
    enhanceSubscriptionLists(document);
}

document.addEventListener('htmx:afterSwap', enhanceArrived);

/*
 * Keyboard and screen-reader continuity across an htmx swap.
 *
 * Every trigger that swaps a region has a stable `id`, and htmx puts focus back
 * on the element with the id it had before. A trigger that is gone after the
 * swap (Next on the last page) leaves focus on the page, so focus falls back to
 * the region's `data-focus-fallback` element. The region's `data-swap-status`
 * line is cleared and refilled so a polite live region, which a screen reader
 * reads only when its text changes, announces the new results.
 */
/*
 * Kept per target region, so two requests in flight at once (typing "net", a
 * pause, then "flix"; choosing Active and then a sort) each keep their own
 * focus id. The triggers that share a region sync on it (`hx-sync` with
 * `:replace`), so the older request is cancelled and only the newest settles.
 */
const pendingSwaps = new Map();

document.addEventListener('htmx:beforeRequest', (event) => {
    const target = event.detail.target;
    if (!(target instanceof Element) || target.id === '') {
        return;
    }
    pendingSwaps.set(target.id, document.activeElement instanceof Element && document.activeElement.id !== ''
        ? document.activeElement.id
        : null);
});

// A request that fails or is cancelled never settles: forget it, unless a
// newer one has already taken its place.
const forgetSwap = (event) => {
    const target = event.detail.target;
    if (target instanceof Element && target.id !== '' && !document.querySelector('.htmx-request')) {
        pendingSwaps.delete(target.id);
    }
};
document.addEventListener('htmx:responseError', forgetSwap);
document.addEventListener('htmx:sendError', forgetSwap);

/*
 * A control that saves on change (`data-save-on-change`) that fails says so.
 *
 * htmx drops an error response, so without this a switch would keep showing
 * "on" for a channel the server still holds off. The message goes in the
 * page's polite live region (`data-save-error`): the plain-text body the error
 * handler writes for an htmx request, or the catalogue's own sentence when
 * nothing answered. A form control is put back to the state its markup came
 * with, which is the state the server holds. A refused change (flash and
 * redirect) and an expired session (`HX-Redirect`) are not errors here and are
 * shown as before.
 */
const saveStatus = () => document.querySelector('[data-save-error]');

const saveFailed = (event) => {
    const control = event.detail.elt;
    const status = saveStatus();
    if (!(control instanceof Element) || control.closest('[data-save-on-change]') === null || status === null) {
        return;
    }

    const xhr = event.detail.xhr;
    const body = xhr && typeof xhr.responseText === 'string' ? xhr.responseText.trim() : '';
    const plain = xhr && /^text\/plain/.test(xhr.getResponseHeader('Content-Type') || '');
    // Cleared and refilled so a repeat of the same failure is read out again.
    status.textContent = '';
    status.textContent = plain && body !== '' ? body : (status.dataset.networkMessage || '');

    control.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach((input) => {
        input.checked = input.defaultChecked;
    });
    control.querySelectorAll('select option').forEach((option) => {
        option.selected = option.defaultSelected;
    });
};
document.addEventListener('htmx:responseError', saveFailed);
document.addEventListener('htmx:sendError', saveFailed);
document.addEventListener('htmx:timeout', saveFailed);

// The next attempt starts clean.
document.addEventListener('htmx:beforeRequest', (event) => {
    const status = saveStatus();
    if (status !== null && event.detail.elt instanceof Element && event.detail.elt.closest('[data-save-on-change]') !== null) {
        status.textContent = '';
    }
});

document.addEventListener('htmx:afterSettle', (event) => {
    const settled = event.detail.target instanceof Element ? event.detail.target.id : '';
    if (settled === '' || !pendingSwaps.has(settled)) {
        return;
    }

    const focusId = pendingSwaps.get(settled);
    pendingSwaps.delete(settled);
    const region = document.getElementById(settled);

    if (region === null) {
        return;
    }

    if (focusId !== null && document.getElementById(focusId) === null) {
        const fallback = region.matches('[data-focus-fallback]')
            ? region
            : region.querySelector('[data-focus-fallback]');
        if (fallback instanceof HTMLElement) {
            fallback.focus();
        }
    }

    const status = region.querySelector('[data-swap-status]');
    if (status instanceof HTMLElement) {
        const text = status.textContent ?? '';
        status.textContent = '';
        window.setTimeout(() => {
            status.textContent = text;
        }, 50);
    }
});
// Back and Forward through an hx-push-url history: htmx restores the markup
// itself and fires no afterSwap, so the sizes have to be applied again.
document.addEventListener('htmx:historyRestore', () => applyGeometry(document));
document.addEventListener('htmx:beforeHistorySave', stripGeometryForHistory);
document.addEventListener('renovo:content-loaded', enhanceArrived);

/*
 * Redraw when the palette or the theme changes.
 *
 * A chart's colours are read from the design tokens at the moment it is drawn,
 * so one drawn in one palette keeps that palette's ink until something asks for
 * it again. Two things can move the tokens under a drawn chart: the system
 * flipping between light and dark (for an account set to "system"), and the
 * root's `data-theme` or `data-palette` changing.
 */
onThemeChange(() => drawCharts());
