/**
 * A read-only field with a Copy button beside it — the calendar feed's
 * address.
 *
 * The field works on its own: it is an ordinary read-only input, so the
 * address can be selected and copied by hand with this script blocked. The
 * button is rendered `hidden` and revealed here, because a button that does
 * nothing without script is worse than no button.
 *
 * Focusing the field selects all of it, since the only thing anyone does with
 * it is copy the whole address.
 */

/*
 * window.renovoI18n is i18n.js's translator, whose t() hands back the key for
 * a string it lacks; then, as without i18n.js, nothing is announced rather
 * than the key being read out.
 */
function message(key) {
    const text = window.renovoI18n ? window.renovoI18n.t(key) : key;

    return text === key ? '' : text;
}

function announce(button, text) {
    const status = button.closest('section')?.querySelector('[data-copy-status]');
    if (status) {
        status.textContent = text;
    }
}

async function copy(input) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(input.value);

        return;
    }

    // An instance served over plain http on a LAN has no Clipboard API; the
    // older command still works there.
    input.select();
    if (!document.execCommand('copy')) {
        throw new Error('copy refused');
    }
}

export function enhanceCopyFields(root) {
    for (const button of root.querySelectorAll('button[data-copy]')) {
        if (button.dataset.copyReady === '1') {
            continue;
        }

        const input = document.getElementById(button.dataset.copy);
        if (!(input instanceof HTMLInputElement)) {
            continue;
        }

        button.dataset.copyReady = '1';
        button.hidden = false;

        input.addEventListener('focus', () => input.select());
        button.addEventListener('click', async () => {
            try {
                await copy(input);
                announce(button, message('copied'));
            } catch {
                input.focus();
                announce(button, message('copy_failed'));
            }
        });
    }
}
