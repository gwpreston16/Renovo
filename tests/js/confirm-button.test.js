import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { loadApp, unloadApp } from './load-app.js';

/*
 * K185: a list of rows posts through one shared form, so the question to ask
 * before Delete lives on the button, not the form. The button that sent the
 * form asks; a sibling button without a question sends unasked.
 */
beforeEach(async () => {
    document.body.innerHTML = `<form id="shared" method="post"></form>
        <button id="delete" type="submit" form="shared" formaction="/x/delete" data-confirm="Delete x?">Delete</button>
        <button id="pause" type="submit" form="shared" formaction="/x/toggle">Pause</button>`;
    await loadApp();
});

afterEach(() => {
    unloadApp();
    vi.restoreAllMocks();
});

function send(buttonId) {
    const form = document.getElementById('shared');
    const event = new SubmitEvent('submit', {
        bubbles: true,
        cancelable: true,
        submitter: document.getElementById(buttonId),
    });
    form.dispatchEvent(event);
    return event;
}

test('a button with a question asks, and a declined question stops the form', () => {
    const ask = vi.spyOn(window, 'confirm').mockReturnValue(false);

    expect(send('delete').defaultPrevented).toBe(true);
    expect(ask).toHaveBeenCalledWith('Delete x?');
});

test('an accepted question lets the form send', () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);

    expect(send('delete').defaultPrevented).toBe(false);
});

test('a button without a question sends unasked', () => {
    const ask = vi.spyOn(window, 'confirm').mockReturnValue(false);

    expect(send('pause').defaultPrevented).toBe(false);
    expect(ask).not.toHaveBeenCalled();
});
