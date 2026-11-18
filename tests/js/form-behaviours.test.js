import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { htmx, loadApp, unloadApp } from './load-app.js';

/*
 * K267: the four form behaviours in assets/js/form-behaviours.js besides
 * `data-confirm` (which confirm-button.test.js covers), each through the
 * listeners app.js binds on the document, so a template's attribute is
 * tested the way a page uses it.
 */
beforeEach(async () => {
    await loadApp();
});

afterEach(() => {
    unloadApp();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

function form(html, attributes = '') {
    document.body.innerHTML = `<form id="f" ${attributes}>${html}</form>`;
    return document.getElementById('f');
}

test('data-autosubmit sends the form once when the control changes', () => {
    const sent = form('<select id="on" data-autosubmit><option>a</option></select><select id="off"></select>');
    // jsdom does not implement form submission; the call is what matters.
    const requestSubmit = vi.spyOn(sent, 'requestSubmit').mockImplementation(() => {});

    document.getElementById('off').dispatchEvent(new Event('change', { bubbles: true }));
    expect(requestSubmit).not.toHaveBeenCalled();

    document.getElementById('on').dispatchEvent(new Event('change', { bubbles: true }));
    expect(requestSubmit).toHaveBeenCalledTimes(1);
});

test('data-clears unticks the named checkbox in the form, and nothing else', () => {
    form(`<input id="auto" type="checkbox" name="auto_colour" checked>
        <input id="mode" type="radio" name="mode" checked>
        <input id="colour" type="color" data-clears="auto_colour">
        <input id="other" type="color" data-clears="mode">`);

    // A colour picker sends `input` as it moves, before any `change`.
    document.getElementById('colour').dispatchEvent(new Event('input', { bubbles: true }));
    expect(document.getElementById('auto').checked).toBe(false);

    // A radio can be unticked too, so it shows the checkbox-only guard holds.
    document.getElementById('other').dispatchEvent(new Event('input', { bubbles: true }));
    expect(document.getElementById('mode').checked).toBe(true);
});

test('data-typed-trigger fires renovo:typed on its form when a field is typed in', () => {
    document.body.innerHTML = `<form id="typed" data-typed-trigger>
            <input id="q" type="text"><input id="n" type="number"><input id="c" type="checkbox">
        </form>
        <form id="plain"><input id="p" type="text"></form>`;
    const typed = vi.fn();
    const plain = vi.fn();
    document.getElementById('typed').addEventListener('renovo:typed', typed);
    document.getElementById('plain').addEventListener('renovo:typed', plain);

    for (const id of ['q', 'n', 'c', 'p']) {
        document.getElementById(id).dispatchEvent(new Event('input', { bubbles: true }));
    }

    // The text and number fields, not the checkbox; nothing from the form without it.
    expect(typed).toHaveBeenCalledTimes(2);
    expect(plain).not.toHaveBeenCalled();
});

test('data-focus-after moves focus once the sending form\'s swap lands', () => {
    document.body.innerHTML = `<form id="sender" data-focus-after="#heading"></form>
        <form id="quiet"></form>
        <h2 id="heading" tabindex="-1">Rows</h2>
        <button id="start">Start</button>`;
    const start = document.getElementById('start');
    start.focus();

    const quiet = document.getElementById('quiet');
    htmx('afterSwap', quiet, { requestConfig: { elt: quiet } });
    expect(document.activeElement).toBe(start);

    const sender = document.getElementById('sender');
    htmx('afterSwap', sender, { requestConfig: { elt: sender } });
    expect(document.activeElement).toBe(document.getElementById('heading'));
});
