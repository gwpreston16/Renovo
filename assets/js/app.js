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
import { applyGeometry } from './geometry.js';
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
