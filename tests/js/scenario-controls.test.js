import { afterEach, beforeEach, expect, test } from 'vitest';
import { loadApp, unloadApp } from './load-app.js';

/*
 * K181: a row's price, cycle and days fields exist only while it is set to
 * Change. Switching to Change is the radio's own htmx request (tested on the
 * server); switching away drops the fields here.
 */
beforeEach(async () => {
    document.body.innerHTML = `<form id="scenario-form" method="post" action="/forecast/scenario">
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
