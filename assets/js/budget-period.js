/**
 * Keeps the budget form's Period group described by the pay-day hint only
 * while the chosen period can follow pay day (K241).
 *
 * The hint ("set your pay day") is hidden by the stylesheet while Yearly is
 * chosen, since a year never follows pay day. Hiding it is not enough for a
 * screen reader: an element named by `aria-describedby` is read even when it
 * is hidden. So its id leaves the group's `aria-describedby` for Yearly and
 * comes back for any other period, and anything else the group is described
 * by (a refused period's error) stays. The server makes the same choice for
 * the period the form opens on, which stands without script.
 */

const HINT_ID = 'budget-pay-day-hint';
const NO_PAY_DAY_PERIOD = 'annual';

let bound = false;

/**
 * Brings one Period group's `aria-describedby` in line with its chosen
 * period. A form with no hint (the member has a pay day) is left alone.
 */
export function describeBudgetPeriod(group) {
    const form = group.closest('form');
    if (!form || !form.querySelector(`#${HINT_ID}`)) {
        return;
    }

    const chosen = group.querySelector('input[name="period"]:checked');
    const ids = (group.getAttribute('aria-describedby') || '').split(/\s+/).filter((id) => id && id !== HINT_ID);
    if (!chosen || chosen.value !== NO_PAY_DAY_PERIOD) {
        // The hint first, as the server writes it.
        ids.unshift(HINT_ID);
    }

    if (ids.length > 0) {
        group.setAttribute('aria-describedby', ids.join(' '));
    } else {
        group.removeAttribute('aria-describedby');
    }
}

/*
 * Delegated, so a form an htmx swap brings in later answers too.
 */
function onPeriodChange(event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.name !== 'period') {
        return;
    }

    const group = input.closest('.budget-period-field');
    if (group) {
        describeBudgetPeriod(group);
    }
}

/**
 * Listens for a change of period on any budget form, once per page.
 */
export function bindBudgetPeriods() {
    if (bound) {
        return;
    }
    bound = true;

    document.addEventListener('change', onPeriodChange);
}
