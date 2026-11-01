import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { htmx, loadApp, unloadApp } from './load-app.js';

beforeEach(async () => {
    vi.useFakeTimers();
    document.body.innerHTML = `
        <input id="q-a"><input id="q-b">
        <section id="results">
            <p data-swap-status>3 results</p>
            <button id="fallback" data-focus-fallback>Results</button>
        </section>`;
    await loadApp();
});

afterEach(() => {
    unloadApp();
    vi.useRealTimers();
});

const region = () => document.getElementById('results');

// Starts a request from `trigger` (focused first) against the results region.
const request = (trigger) => {
    document.getElementById(trigger).focus();
    htmx('beforeRequest', document.getElementById(trigger), {target: region()});
};

// The server's answer replaces the trigger (or not) and htmx settles the region.
const settle = () => htmx('afterSettle', region(), {target: region()});

test('overlapping requests keep the newest focus id', () => {
    request('q-a');
    request('q-b');
    document.getElementById('q-b').remove();
    document.getElementById('q-a').focus();

    settle();

    // The newest request's trigger (q-b) is gone, so focus falls back; the
    // older one's id (q-a, still present) would have left focus where it was.
    expect(document.activeElement.id).toBe('fallback');
});

test('an older request does not decide the focus once a newer one has settled', () => {
    request('q-a');
    request('q-b');
    settle();
    document.getElementById('q-b').remove();
    document.getElementById('q-a').focus();

    settle(); // nothing pending: the entry was consumed by the first settle

    expect(document.activeElement.id).toBe('q-a');
});

test('an error forgets the region unless a newer request is pending', () => {
    request('q-a');
    document.getElementById('q-a').remove();
    htmx('responseError', document.body, {target: region()});
    settle();
    expect(document.activeElement.id).not.toBe('fallback');

    // A newer request still in flight (htmx marks it with .htmx-request) keeps the entry.
    request('q-b');
    const inFlight = document.createElement('button');
    inFlight.className = 'htmx-request';
    document.body.append(inFlight);
    document.getElementById('q-b').remove();
    htmx('sendError', document.body, {target: region()});
    settle();
    expect(document.activeElement.id).toBe('fallback');
});

test('a send error forgets the region too', () => {
    request('q-a');
    document.getElementById('q-a').remove();
    htmx('sendError', document.body, {target: region()});
    settle();
    expect(document.activeElement.id).not.toBe('fallback');
});

test('focus falls back when the trigger is gone', () => {
    request('q-a');
    document.getElementById('q-a').remove();
    settle();
    expect(document.activeElement.id).toBe('fallback');
});

test('focus is left alone while the trigger is still there', () => {
    request('q-a');
    settle();
    expect(document.activeElement.id).toBe('q-a');
});

test('a region with no id is ignored', () => {
    const anonymous = document.createElement('div');
    document.body.append(anonymous);
    request('q-a');
    htmx('beforeRequest', document.getElementById('q-a'), {target: anonymous});
    document.getElementById('q-a').remove();
    settle();
    expect(document.activeElement.id).toBe('fallback');
});

test('the swap status is cleared and refilled', () => {
    const status = document.querySelector('[data-swap-status]');
    request('q-a');
    settle();

    expect(status.textContent).toBe('');
    vi.advanceTimersByTime(50);
    expect(status.textContent).toBe('3 results');
});
