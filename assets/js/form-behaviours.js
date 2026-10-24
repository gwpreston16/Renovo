/**
 * What forms used to do through inline handlers, as delegated listeners.
 *
 * The Content-Security-Policy runs no inline script (Phase 41), and that
 * includes `onsubmit=` and `onchange=` attributes. The templates say what they
 * want with data attributes instead, and one listener on the document answers
 * for every form on the page, including forms an htmx swap brings in later.
 *
 *   - `data-confirm="Question?"` on a form: asks before it is sent, and a
 *     declined question stops it. Without script the form sends unasked, as
 *     it did when JavaScript was off before.
 *   - `data-autosubmit` on a control: sends its form when it changes.
 *   - `data-typed-trigger` on a form: typing in one of its text or number
 *     fields fires `renovo:typed` on the form, for an `hx-trigger` to wait
 *     on. htmx's own event filters would need `eval`, which the policy
 *     refuses.
 */

/*
 * Capture phase, so the question is asked before any listener on the form
 * itself (htmx's included) sees the submit, and a declined one never reaches
 * them.
 */
function confirmBeforeSending(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) {
        return;
    }

    if (!window.confirm(form.getAttribute('data-confirm'))) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}

function submitOnChange(event) {
    const control = event.target;
    if (!(control instanceof Element) || !control.hasAttribute('data-autosubmit') || !control.form) {
        return;
    }

    // requestSubmit, not submit: it fires the submit event, so a form that
    // asks first, or that htmx sends, behaves as if its button were pressed.
    control.form.requestSubmit();
}

function announceTyping(event) {
    const field = event.target;
    if (!(field instanceof HTMLInputElement) || (field.type !== 'text' && field.type !== 'number')) {
        return;
    }

    const form = field.closest('form[data-typed-trigger]');
    if (form) {
        form.dispatchEvent(new CustomEvent('renovo:typed', { bubbles: true }));
    }
}

let bound = false;

export function bindFormBehaviours() {
    if (bound) {
        return;
    }
    bound = true;

    document.addEventListener('submit', confirmBeforeSending, true);
    document.addEventListener('change', submitOnChange);
    document.addEventListener('input', announceTyping);
}
