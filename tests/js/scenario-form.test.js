import { afterEach, beforeEach, expect, test } from 'vitest';
import { loadApp, unloadApp } from './load-app.js';

const ROWS = 300;

/*
 * The planner form as the page draws it: every subscription has a Keep /
 * Cancel / Change choice and three amount fields.
 */
function row(id, choice) {
    const radio = (value) =>
        `<input type="radio" name="choice[${id}]" value="${value}"${choice === value ? ' checked' : ''}>`;

    return `<li>${radio('keep')}${radio('cancel')}${radio('change')}
        <input type="text" name="change[${id}][price]" value="">
        <select name="change[${id}][cycle]"><option value="" selected></option></select>
        <input type="number" name="change[${id}][days]" value=""></li>`;
}

function planner(choices = {}) {
    let rows = '';
    for (let id = 1; id <= ROWS; id++) {
        rows += row(id, choices[id] ?? 'keep');
    }
    document.body.innerHTML = `<form id="scenario-form" method="post" action="/forecast/scenario"><ul>${rows}</ul>
        <button type="submit">Work it out</button></form>`;
}

beforeEach(async () => {
    planner({ 251: 'cancel', 300: 'change' });
    await loadApp();
});

afterEach(unloadApp);

/** An htmx request for the planner, as the form's own change trigger makes it. */
function request(path = '/forecast/scenario') {
    const parameters = {};
    for (const field of document.getElementById('scenario-form').elements) {
        if (!field.name || (field.type === 'radio' && !field.checked)) {
            continue;
        }
        parameters[field.name] = field.value;
    }
    const event = new CustomEvent('htmx:configRequest', { bubbles: true, detail: { parameters, path } });
    document.getElementById('scenario-form').dispatchEvent(event);

    return Object.keys(parameters);
}

test('a request carries only the rows that are not Keep', () => {
    const names = request();

    expect(names).toContain('choice[251]');
    expect(names).toContain('choice[300]');
    expect(names).toContain('change[300][price]');
    expect(names.filter((name) => name.startsWith('choice['))).toHaveLength(2);
    expect(names.length).toBeLessThan(10);
});

test('a cancel on the last row survives the next change', () => {
    document.querySelector('input[name="choice[7]"][value="cancel"]').checked = true;

    const names = request();

    expect(names).toContain('choice[7]');
    expect(names).toContain('choice[251]');
    expect(names).toContain('choice[300]');
});

test('a row set back to Keep drops its typed amount from the request but not from the form', () => {
    const price = document.querySelector('input[name="change[300][price]"]');
    price.value = '9.99';
    document.querySelector('input[name="choice[300]"][value="keep"]').checked = true;

    const names = request();

    expect(names).not.toContain('change[300][price]');
    expect(names).not.toContain('choice[300]');
    expect(price.value).toBe('9.99');
});

test('a request for something else is left alone', () => {
    expect(request('/subscriptions')).toHaveLength(ROWS * 4);
});

test('a filter that includes the form is trimmed too', () => {
    expect(request('/forecast/scenario?category=3').length).toBeLessThan(10);
});

test('the form\'s own submit leaves Keep rows out and puts them back', async () => {
    const form = document.getElementById('scenario-form');
    let sentDuringSubmit = [];
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        queueMicrotask(() => {
            sentDuringSubmit = [...form.elements].filter((field) => field.name && !field.disabled).map((field) => field.name);
        });
    });

    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await Promise.resolve();

    expect(sentDuringSubmit.filter((name) => name.startsWith('choice['))).toEqual(
        expect.arrayContaining(['choice[251]', 'choice[300]']),
    );
    expect(sentDuringSubmit).not.toContain('choice[1]');

    await new Promise((resolve) => setTimeout(resolve, 5));
    expect([...form.elements].some((field) => field.disabled)).toBe(false);
});

test('the form\'s token stays out of a request for the results', () => {
    document.getElementById('scenario-form').insertAdjacentHTML('beforeend', '<input type="hidden" name="_csrf" value="t">');

    expect(request()).not.toContain('_csrf');
});
