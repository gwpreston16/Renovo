import { beforeEach, expect, test } from 'vitest';
import { bindBudgetPeriods, describeBudgetPeriod } from '../../assets/js/budget-period.js';

/*
 * K241: the budget form's Period group is described by the pay-day hint only
 * while the chosen period can follow pay day. A hidden element named by
 * aria-describedby is still read, so hiding the hint for Yearly is not enough.
 */

function form({ period = 'monthly', hint = true, error = false } = {}) {
    const described = [hint && period !== 'annual' ? 'budget-pay-day-hint' : '', error ? 'budget-period-error' : '']
        .filter(Boolean).join(' ');
    const option = (value) => `<label><input type="radio" name="period" value="${value}"${value === period ? ' checked' : ''}></label>`;

    return '<form class="budget-form">'
        + `<div class="field budget-period-field" role="radiogroup"${described ? ` aria-describedby="${described}"` : ''}>`
        + option('monthly') + option('fortnightly') + option('annual')
        + (error ? '<p class="field-error" id="budget-period-error">Pick a period.</p>' : '')
        + '</div>'
        + (hint ? '<p class="hint" id="budget-pay-day-hint">Set your pay day</p>' : '')
        + '</form>';
}

const group = () => document.querySelector('.budget-period-field');
const described = () => group().getAttribute('aria-describedby');

function choose(value) {
    const input = document.querySelector(`input[name="period"][value="${value}"]`);
    input.checked = true;
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

beforeEach(() => {
    document.body.innerHTML = '';
    bindBudgetPeriods();
});

test('choosing Yearly drops the hint from the description', () => {
    document.body.innerHTML = form();

    choose('annual');

    expect(group().hasAttribute('aria-describedby')).toBe(false);
});

test('choosing Yearly keeps an error in the description', () => {
    document.body.innerHTML = form({ error: true });

    choose('annual');

    expect(described()).toBe('budget-period-error');
});

test('choosing Monthly again puts the hint back, first', () => {
    document.body.innerHTML = form({ period: 'annual', error: true });

    choose('monthly');
    expect(described()).toBe('budget-pay-day-hint budget-period-error');

    choose('fortnightly');
    expect(described()).toBe('budget-pay-day-hint budget-period-error');
});

test('a member with a pay day has no hint, and the group is left alone', () => {
    document.body.innerHTML = form({ hint: false, error: true });

    choose('monthly');

    expect(described()).toBe('budget-period-error');
});

test('a group with nothing chosen is described by the hint', () => {
    document.body.innerHTML = form({ period: 'none' });

    describeBudgetPeriod(group());

    expect(described()).toBe('budget-pay-day-hint');
});

test('a change to another control, even inside the group, is ignored', () => {
    // Monthly with no description: if the handler ran, it would add the hint.
    document.body.innerHTML = form();
    group().removeAttribute('aria-describedby');
    group().insertAdjacentHTML('beforeend', '<input type="checkbox" name="other">');

    document.querySelector('input[name="other"]').dispatchEvent(new Event('change', { bubbles: true }));

    expect(group().hasAttribute('aria-describedby')).toBe(false);
});

test('a period radio outside a Period group is ignored', () => {
    document.body.innerHTML = form() + '<input type="radio" name="period" value="annual">';
    const stray = document.querySelector('body > input[name="period"]');
    stray.checked = true;
    stray.dispatchEvent(new Event('change', { bubbles: true }));

    expect(described()).toBe('budget-pay-day-hint');
});
