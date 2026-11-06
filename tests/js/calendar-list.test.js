import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { htmx, loadApp, unloadApp } from './load-app.js';

const MONTH = '<div id="calendar-month"><div class="calendar-narrow" data-calendar-list="/calendar/list?month=2026-09">'
    + '<p class="calendar-list-link"><a href="/calendar?view=list">Show as list</a></p>'
    + '<p class="calendar-list-loading" role="status" hidden>Loading the list…</p></div></div>';

/** Pretend the window is (or is not) phone-sized. */
function viewport(narrow) {
    window.matchMedia = vi.fn(() => ({
        matches: narrow,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }));
}

function answerWith(html) {
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve(html) }));
}

beforeEach(() => {
    document.body.innerHTML = MONTH;
});

afterEach(() => {
    unloadApp();
    vi.restoreAllMocks();
});

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

test('a desktop-width window never asks for the list', async () => {
    viewport(false);
    answerWith('<ol class="calendar-list"></ol>');
    await loadApp();
    await settle();

    expect(fetch).not.toHaveBeenCalled();
    expect(document.querySelector('.calendar-narrow a')).not.toBeNull();
});

test('a phone-width window asks for the month list and puts it in place of the link', async () => {
    viewport(true);
    answerWith('<ol class="calendar-list"><li>20 Sep</li></ol>');
    await loadApp();
    await settle();

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][0]).toBe('/calendar/list?month=2026-09');
    expect(document.querySelector('.calendar-narrow .calendar-list')).not.toBeNull();
    expect(document.querySelector('.calendar-narrow a')).toBeNull();
});

test('a swapped-in month asks again for its own list', async () => {
    viewport(true);
    answerWith('<ol class="calendar-list"></ol>');
    await loadApp();
    await settle();

    document.body.innerHTML = MONTH.replace('2026-09', '2026-10');
    htmx('afterSwap', document.getElementById('calendar-month'));
    await settle();

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(fetch.mock.calls[1][0]).toBe('/calendar/list?month=2026-10');
});

test('a failed request leaves the link where it was', async () => {
    viewport(true);
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500, text: () => Promise.resolve('') }));
    await loadApp();
    await settle();

    expect(document.querySelector('.calendar-narrow a')).not.toBeNull();
});

test('while the list loads the link gives way to a busy loading line', async () => {
    viewport(true);
    let answer;
    globalThis.fetch = vi.fn(() => new Promise((resolve) => { answer = resolve; }));
    await loadApp();

    const container = document.querySelector('.calendar-narrow');
    expect(container.getAttribute('aria-busy')).toBe('true');
    expect(container.querySelector('.calendar-list-link').hidden).toBe(true);
    expect(container.querySelector('.calendar-list-loading').hidden).toBe(false);

    answer({ ok: true, status: 200, text: () => Promise.resolve('<ol class="calendar-list"></ol>') });
    await settle();
    await settle();

    expect(container.hasAttribute('aria-busy')).toBe(false);
    expect(container.querySelector('.calendar-list-loading')).toBeNull();
});

test('a failed request shows the link again and drops the loading line', async () => {
    viewport(true);
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500, text: () => Promise.resolve('') }));
    await loadApp();
    await settle();

    const container = document.querySelector('.calendar-narrow');
    expect(container.hasAttribute('aria-busy')).toBe(false);
    expect(container.querySelector('.calendar-list-link').hidden).toBe(false);
    expect(container.querySelector('.calendar-list-loading').hidden).toBe(true);
});
