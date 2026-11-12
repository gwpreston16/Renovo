import { afterEach, beforeEach, expect, it, test, vi } from 'vitest';
import { htmx, loadApp, unloadApp } from './load-app.js';

/*
 * K181: a row's price, cycle and days fields exist only while it is set to
 * Change. Switching to Change is the radio's own htmx request (tested on the
 * server); switching away drops the fields here.
 */
beforeEach(async () => {
    // jsdom has no layout, so scrollIntoView is absent.
    Element.prototype.scrollIntoView = vi.fn();
    document.body.innerHTML = `<div data-save-error role="status"></div>
    <form id="scenario-form" method="post" action="/forecast/scenario"
          data-change-failed="The price fields did not load.">
        <input type="radio" name="choice[5]" value="keep">
        <input type="radio" name="choice[5]" value="cancel">
        <input type="radio" name="choice[5]" value="change" checked>
        <div id="scenario-change-5"><input type="text" name="change[5][price]" value="9.99"></div>
        <div id="scenario-change-6"><input type="text" name="change[6][price]" value="1.00"></div>
    </form>`;
    await loadApp();
});

afterEach(unloadApp);

function choose(value) {
    const radio = document.querySelector(`input[name="choice[5]"][value="${value}"]`);
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
}

test('choosing Keep or Cancel empties that row\'s controls and no other', () => {
    choose('keep');

    expect(document.getElementById('scenario-change-5').children).toHaveLength(0);
    expect(document.getElementById('scenario-change-6').children).toHaveLength(1);
});

test('choosing Cancel empties them too', () => {
    choose('cancel');

    expect(document.getElementById('scenario-change-5').children).toHaveLength(0);
});

test('choosing Change leaves the slot for the radio\'s own request to fill', () => {
    choose('change');

    expect(document.getElementById('scenario-change-5').children).toHaveLength(1);
});

test('controls that fail to load put the row back on Keep and say so', () => {
    const radio = document.querySelector('input[name="choice[5]"][value="change"]');
    htmx('responseError', radio, { target: document.getElementById('scenario-change-5') });

    expect(document.querySelector('input[name="choice[5]"][value="keep"]').checked).toBe(true);
    expect(document.getElementById('scenario-change-5').children).toHaveLength(0);
    expect(document.querySelector('[data-save-error]').textContent).toBe('The price fields did not load.');
});

test('a failure somewhere else on the page leaves the planner alone', () => {
    const other = document.createElement('div');
    other.id = 'somewhere-else';
    document.body.append(other);
    htmx('sendError', other, { target: other });

    expect(document.querySelector('input[name="choice[5]"][value="change"]').checked).toBe(true);
    expect(document.querySelector('[data-save-error]').textContent).toBe('');
});

test('choosing Change again clears the failure from the status line', () => {
    const slot = document.getElementById('scenario-change-5');
    const radio = document.querySelector('input[name="choice[5]"][value="change"]');
    htmx('responseError', radio, { target: slot });
    htmx('beforeRequest', radio, { target: slot });

    expect(document.querySelector('[data-save-error]').textContent).toBe('');
});

/** Fire htmx's beforeSwap for a 200 into `slot`, and hand back the detail htmx would read. */
function beforeSwap(elt, slot) {
    const detail = { elt, target: slot, shouldSwap: true, isError: false };
    elt.dispatchEvent(new CustomEvent('htmx:beforeSwap', { bubbles: true, detail }));

    return detail;
}

// K230: a row the reader has moved off Change keeps the choice they made.
test('a row moved to Cancel stays there when its Change fields fail', () => {
    const slot = document.getElementById('scenario-change-5');
    const change = document.querySelector('input[name="choice[5]"][value="change"]');
    choose('cancel');
    htmx('responseError', change, { target: slot });

    expect(document.querySelector('input[name="choice[5]"][value="cancel"]').checked).toBe(true);
    expect(slot.children).toHaveLength(0);
    expect(document.querySelector('[data-save-error]').textContent).toBe('');
});

test('a late Change answer is not drawn into a row on Keep', () => {
    const slot = document.getElementById('scenario-change-5');
    const change = document.querySelector('input[name="choice[5]"][value="change"]');
    choose('keep');
    const detail = beforeSwap(change, slot);

    expect(detail.shouldSwap).toBe(false);
    expect(slot.children).toHaveLength(0);
});

test('an answer for a row still on Change is drawn', () => {
    const change = document.querySelector('input[name="choice[5]"][value="change"]');
    const detail = beforeSwap(change, document.getElementById('scenario-change-5'));

    expect(detail.shouldSwap).toBe(true);
});

test('a swap anywhere else on the page is left to htmx', () => {
    choose('keep');
    const other = document.createElement('div');
    other.id = 'scenario-results';
    document.body.append(other);

    expect(beforeSwap(other, other).shouldSwap).toBe(true);
});

// K234: each kind of failure htmx reports is handled, not only a 4xx/5xx.
it.each(['responseError', 'sendError', 'timeout'])('every kind of failure puts the row back on Keep (%s)', (kind) => {
    const slot = document.getElementById('scenario-change-5');
    const change = document.querySelector('input[name="choice[5]"][value="change"]');
    htmx(kind, change, { target: slot });

    expect(document.querySelector('input[name="choice[5]"][value="keep"]').checked).toBe(true);
    expect(slot.children).toHaveLength(0);
    expect(document.querySelector('[data-save-error]').textContent).toBe('The price fields did not load.');
});

test('a fresh request keeps another message', () => {
    const status = document.querySelector('[data-save-error]');
    status.textContent = 'Could not save your changes.';
    const change = document.querySelector('input[name="choice[5]"][value="change"]');
    htmx('beforeRequest', change, { target: document.getElementById('scenario-change-5') });

    expect(status.textContent).toBe('Could not save your changes.');
});
