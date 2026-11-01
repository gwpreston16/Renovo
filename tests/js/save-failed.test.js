import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { htmx, loadApp, unloadApp } from './load-app.js';

beforeEach(async () => {
    document.body.innerHTML = `
        <p data-save-error data-network-message="No answer from the server."></p>
        <form id="prefs" data-save-on-change>
            <input type="checkbox" id="on" checked>
            <input type="checkbox" id="off">
            <input type="radio" name="r" id="r1" checked>
            <input type="radio" name="r" id="r2">
            <select id="s"><option value="a" selected>A</option><option value="b">B</option></select>
            <input type="text" id="t" value="stored">
        </form>
        <input type="checkbox" id="elsewhere" checked>`;
    // jsdom has no layout, so scrollIntoView is absent.
    Element.prototype.scrollIntoView = vi.fn();
    await loadApp();
});

afterEach(unloadApp);

const status = () => document.querySelector('[data-save-error]');
const form = () => document.getElementById('prefs');

// The user's change, which the server then refuses.
const change = () => {
    document.getElementById('on').checked = false;
    document.getElementById('off').checked = true;
    document.getElementById('r2').checked = true;
    document.getElementById('s').value = 'b';
    document.getElementById('t').value = 'typed';
};

const xhr = (text, type) => ({
    responseText: text,
    getResponseHeader: (name) => (name === 'Content-Type' ? type : null),
});

test('a failed save shows the response body', () => {
    change();
    htmx('responseError', form(), {xhr: xhr('  Could not save.  ', 'text/plain; charset=utf-8')});

    expect(status().textContent).toBe('Could not save.');
    expect(Element.prototype.scrollIntoView).toHaveBeenCalledWith({block: 'nearest'});
});

test('an HTML error page is not shown; the catalogue sentence is', () => {
    htmx('responseError', form(), {xhr: xhr('<html>500</html>', 'text/html')});

    expect(status().textContent).toBe('No answer from the server.');
});

test('a plain-text error with an empty body shows the network message', () => {
    htmx('responseError', form(), {xhr: xhr('   ', 'text/plain')});

    expect(status().textContent).toBe('No answer from the server.');
});

test('no answer shows the catalogue\'s network message', () => {
    htmx('sendError', form(), {});
    expect(status().textContent).toBe('No answer from the server.');

    status().textContent = '';
    htmx('timeout', form(), {});
    expect(status().textContent).toBe('No answer from the server.');
});

test('checkboxes, radios and selects revert and text is left alone', () => {
    change();
    htmx('sendError', form(), {});

    expect(document.getElementById('on').checked).toBe(true);
    expect(document.getElementById('off').checked).toBe(false);
    expect(document.getElementById('r1').checked).toBe(true);
    expect(document.getElementById('r2').checked).toBe(false);
    expect(document.getElementById('s').value).toBe('a');
    expect(document.getElementById('t').value).toBe('typed');
});

test('a control outside a save-on-change region is not touched', () => {
    const other = document.getElementById('elsewhere');
    other.checked = false;
    htmx('sendError', other, {});

    expect(status().textContent).toBe('');
    expect(other.checked).toBe(false);
});

test('a new request clears the message', () => {
    htmx('sendError', form(), {});
    expect(status().textContent).not.toBe('');

    htmx('beforeRequest', form(), {target: form()});
    expect(status().textContent).toBe('');
});

test('a request from outside a save-on-change region leaves the message', () => {
    htmx('sendError', form(), {});
    htmx('beforeRequest', document.getElementById('elsewhere'), {target: document.body});

    expect(status().textContent).toBe('No answer from the server.');
});
