import { vi } from 'vitest';

/*
 * assets/js/app.js registers its listeners on `document` when it is imported,
 * and the document outlives a test. Each load is fresh (the module registry is
 * reset) and every listener it added is removed again, so one test's copy of
 * the handlers never answers another test's events.
 */
const added = [];
const original = document.addEventListener.bind(document);

export async function loadApp() {
    vi.resetModules();
    document.addEventListener = (type, listener, options) => {
        added.push([type, listener, options]);
        original(type, listener, options);
    };
    await import('../../assets/js/app.js');
    document.addEventListener = original;
}

export function unloadApp() {
    for (const [type, listener, options] of added.splice(0)) {
        document.removeEventListener(type, listener, options);
    }
}

/** Dispatch an htmx event the way htmx does: bubbling from `element`, with a detail. */
export function htmx(name, element, detail = {}) {
    element.dispatchEvent(new CustomEvent(`htmx:${name}`, {bubbles: true, detail: {elt: element, ...detail}}));
}
