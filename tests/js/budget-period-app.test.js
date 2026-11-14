import { afterEach, expect, test, vi } from 'vitest';
import { loadApp, unloadApp } from './load-app.js';

/*
 * K241's listener is bound by the application itself on page load: without
 * this, budget-period.test.js would pass with bindBudgetPeriods() taken out
 * of app.js.
 */

afterEach(() => {
    unloadApp();
    delete window.matchMedia;
});

test('on a loaded page, choosing Yearly drops the pay-day hint from the description', async () => {
    window.matchMedia = vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
    document.body.innerHTML = '<form class="budget-form">'
        + '<div class="field budget-period-field" role="radiogroup" aria-describedby="budget-pay-day-hint">'
        + '<input type="radio" name="period" value="monthly" checked>'
        + '<input type="radio" name="period" value="annual"></div>'
        + '<p class="hint" id="budget-pay-day-hint">Set your pay day</p></form>';

    await loadApp();
    const annual = document.querySelector('input[value="annual"]');
    annual.checked = true;
    annual.dispatchEvent(new Event('change', { bubbles: true }));

    expect(document.querySelector('.budget-period-field').hasAttribute('aria-describedby')).toBe(false);
});
