import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { enhanceCopyFields } from '../../assets/js/copy-field.js';

/*
 * The calendar feed's Copy button says whether the copy worked, in the words
 * the catalogue gives through window.renovoI18n.t() (public/assets/i18n.js).
 */

function i18n(messages) {
    return { t: (key) => messages[key] || key };
}

const status = () => document.querySelector('[data-copy-status]');

async function clickCopy() {
    document.querySelector('button[data-copy]').click();
    await new Promise((resolve) => setTimeout(resolve, 0));
}

beforeEach(() => {
    document.body.innerHTML = '<section><input id="feed" readonly value="https://example.test/feed.ics">'
        + '<button type="button" data-copy="feed" hidden>Copy</button>'
        + '<p data-copy-status role="status"></p></section>';
    window.renovoI18n = i18n({ copied: 'Copied.', copy_failed: 'That could not be copied.' });
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    enhanceCopyFields(document);
});

afterEach(() => {
    delete window.renovoI18n;
    delete navigator.clipboard;
});

test('a copy that works says so', async () => {
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockResolvedValue() }, configurable: true });

    await clickCopy();

    expect(status().textContent).toBe('Copied.');
});

test('a copy that fails says so', async () => {
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: vi.fn().mockRejectedValue(new Error('no')) },
        configurable: true,
    });

    await clickCopy();

    expect(status().textContent).toBe('That could not be copied.');
});

test('without the string, nothing is announced rather than its key', async () => {
    window.renovoI18n = i18n({});
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockResolvedValue() }, configurable: true });

    await clickCopy();

    expect(status().textContent).toBe('');
});
