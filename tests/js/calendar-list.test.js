import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { htmx, loadApp, unloadApp } from './load-app.js';

// The month partial's phone form: the loading status stands just before the
// container the list replaces (K233), as `templates/calendar/_month.twig` draws it.
const MONTH = '<div id="calendar-month"><div class="calendar-narrow">'
    + '<p class="calendar-list-loading" role="status" data-loading-text="Loading the list…"></p>'
    + '<div data-calendar-list="/calendar/list?month=2026-09">'
    + '<p class="calendar-list-link"><a href="/calendar?view=list">Show as list</a></p></div></div></div>';

/** Pretend the window is (or is not) phone-sized. */
function viewport(narrow) {
    window.matchMedia = vi.fn(() => ({
        matches: narrow,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }));
}

/** A fetch response as `load()` reads it: status, headers and body. */
function response(status, html = '', headers = {}) {
    return { ok: status >= 200 && status < 300, status, headers: new Headers(headers), text: () => Promise.resolve(html) };
}

function answerWith(html) {
    globalThis.fetch = vi.fn(() => Promise.resolve(response(200, html)));
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
    globalThis.fetch = vi.fn(() => Promise.resolve(response(500)));
    await loadApp();
    await settle();

    expect(document.querySelector('.calendar-narrow a')).not.toBeNull();
});

test('while the list loads the link gives way to a busy loading line', async () => {
    viewport(true);
    let answer;
    globalThis.fetch = vi.fn(() => new Promise((resolve) => { answer = resolve; }));
    await loadApp();

    const container = document.querySelector('[data-calendar-list]');
    const status = document.querySelector('.calendar-list-loading');
    expect(container.getAttribute('aria-busy')).toBe('true');
    expect(container.querySelector('.calendar-list-link').hidden).toBe(true);
    expect(status.textContent).toBe('Loading the list…');

    answer(response(200, '<ol class="calendar-list"></ol>'));
    await settle();
    await settle();

    expect(container.hasAttribute('aria-busy')).toBe(false);
    expect(status.textContent).toBe('');
});

test('a failed request shows the link again and drops the loading line', async () => {
    viewport(true);
    globalThis.fetch = vi.fn(() => Promise.resolve(response(500)));
    await loadApp();
    await settle();

    const container = document.querySelector('[data-calendar-list]');
    const status = document.querySelector('.calendar-list-loading');
    expect(container.hasAttribute('aria-busy')).toBe(false);
    expect(container.querySelector('.calendar-list-link').hidden).toBe(false);
    expect(status.textContent).toBe('');
});

/** A list request that stays out until the test answers it. */
function pendingFetch() {
    const answers = [];
    globalThis.fetch = vi.fn(() => new Promise((resolve) => { answers.push(resolve); }));

    return answers;
}

// K233
test('the loading status is announced outside the busy list', async () => {
    viewport(true);
    pendingFetch();
    await loadApp();

    const container = document.querySelector('[data-calendar-list]');
    const status = document.querySelector('.calendar-list-loading');
    expect(container.getAttribute('aria-busy')).toBe('true');
    expect(container.contains(status)).toBe(false);
    expect(status.getAttribute('role')).toBe('status');
    expect(status.textContent).toBe('Loading the list…');
});

// K231
test('Back to a month whose list was loading loads it again', async () => {
    viewport(true);
    const answers = pendingFetch();
    await loadApp();

    const container = document.querySelector('[data-calendar-list]');
    htmx('beforeHistorySave', document.body);

    // The snapshot holds the page as a reload would draw it.
    expect(container.dataset.listState).toBeUndefined();
    expect(container.hasAttribute('aria-busy')).toBe(false);
    expect(container.querySelector('.calendar-list-link').hidden).toBe(false);
    expect(document.querySelector('.calendar-list-loading').textContent).toBe('');

    // The request that was out answers late: it is not drawn into the snapshot.
    answers[0](response(200, '<ol class="calendar-list"><li>stale</li></ol>'));
    await settle();
    await settle();
    expect(container.querySelector('.calendar-list')).toBeNull();

    // Back: htmx puts the saved markup in place and fires historyRestore.
    document.body.innerHTML = MONTH;
    htmx('historyRestore', document.body);
    expect(fetch).toHaveBeenCalledTimes(2);
    answers[1](response(200, '<ol class="calendar-list"><li>20 Sep</li></ol>'));
    await settle();
    await settle();

    expect(document.querySelector('[data-calendar-list] .calendar-list')).not.toBeNull();
    expect(document.querySelector('[data-calendar-list]').dataset.listState).toBe('loaded');
});

// K232
test("the list request says it is htmx's", async () => {
    viewport(true);
    answerWith('<ol class="calendar-list"></ol>');
    await loadApp();
    await settle();

    expect(fetch.mock.calls[0][1].headers).toEqual({ 'HX-Request': 'true' });
});

test('an ended session sends the page to sign-in', async () => {
    viewport(true);
    const assign = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });
    globalThis.fetch = vi.fn(() => Promise.resolve(response(204, '', { 'HX-Redirect': '/login?next=%2Fcalendar' })));
    await loadApp();
    await settle();
    await settle();

    expect(assign).toHaveBeenCalledWith('/login?next=%2Fcalendar');
    const container = document.querySelector('[data-calendar-list]');
    expect(container.querySelector('.calendar-list-link')).not.toBeNull();
    expect(container.dataset.listState).not.toBe('loaded');
});

// K234: a failed list goes back to its link and may be asked for again.
test('a failed list is asked for again', async () => {
    viewport(true);
    globalThis.fetch = vi.fn()
        .mockResolvedValueOnce(response(500))
        .mockResolvedValueOnce(response(200, '<ol class="calendar-list"><li>20 Sep</li></ol>'));
    await loadApp();
    await settle();

    htmx('afterSwap', document.getElementById('calendar-month'));
    await settle();
    await settle();

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(document.querySelector('[data-calendar-list] .calendar-list')).not.toBeNull();
});

test('a window narrowed to a phone fetches its list', async () => {
    let narrow = false;
    const listeners = [];
    window.matchMedia = vi.fn(() => ({
        get matches() { return narrow; },
        addEventListener: (type, listener) => listeners.push(listener),
        removeEventListener: vi.fn(),
    }));
    answerWith('<ol class="calendar-list"><li>20 Sep</li></ol>');
    await loadApp();
    await settle();
    expect(fetch).not.toHaveBeenCalled();

    narrow = true;
    listeners.forEach((listener) => listener({ matches: true }));
    await settle();
    await settle();

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(document.querySelector('[data-calendar-list] .calendar-list')).not.toBeNull();
});
