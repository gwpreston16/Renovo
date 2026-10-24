/**
 * The sizes and colours the server works out per element, applied as styles.
 *
 * Bars, meters, markers and swatches used to carry an inline `style=`. The
 * Content-Security-Policy refuses inline style (Phase 41), so the template
 * writes the value into a `data-css-*` attribute instead and this sets it
 * through the CSSOM — `element.style.setProperty()`, which the policy does
 * not govern. The server still decides every value; this only moves it.
 *
 * Only the properties below, and only values of the shape each one expects,
 * are applied: a `data-css-*` attribute is a channel for a figure, never for
 * arbitrary CSS.
 */

const LENGTH = /^\d{1,3}(\.\d+)?%$/;
// A hex colour as categories and payment methods store it, or a design token.
const COLOUR = /^(#[0-9a-f]{3}([0-9a-f]{3})?|var\(--[a-z0-9-]+\))$/i;

const PROPERTIES = {
    cssWidth: ['width', LENGTH],
    cssHeight: ['height', LENGTH],
    cssLeft: ['left', LENGTH],
    cssTop: ['top', LENGTH],
    cssBottom: ['bottom', LENGTH],
    cssBackground: ['background-color', COLOUR],
    cssStroke: ['stroke', COLOUR],
};

const SELECTOR = Object.keys(PROPERTIES)
    .map((name) => '[data-' + name.replace(/[A-Z]/g, (letter) => '-' + letter.toLowerCase()) + ']')
    .join(',');

/**
 * Take the applied styles off before htmx snapshots the page for its history,
 * and put them straight back. The snapshot is markup, and a `style=` in markup
 * is exactly what the policy refuses when Back restores it; the restore puts
 * them on again through `applyGeometry`. htmx copies the page synchronously
 * after this event, so the microtask runs once the copy is taken and before
 * anything is painted.
 */
export function stripGeometryForHistory() {
    document.querySelectorAll(SELECTOR).forEach((element) => element.removeAttribute('style'));
    queueMicrotask(() => applyGeometry(document));
}

export function applyGeometry(root = document) {
    root.querySelectorAll(SELECTOR).forEach((element) => {
        Object.entries(PROPERTIES).forEach(([name, [property, shape]]) => {
            const value = element.dataset[name];
            if (value !== undefined && shape.test(value)) {
                element.style.setProperty(property, value);
            }
        });
    });
}
