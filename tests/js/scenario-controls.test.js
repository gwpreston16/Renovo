import { afterEach, beforeEach, expect, test, vi } from 'vitest';
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
