import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { readFileSync } from 'node:fs';

/*
 * Adding a passkey asks for the password again, and the server checks it
 * before it hands out the challenge (K174). The script is what carries the
 * typed password, so these tests pin that: the field the button names is the
 * field read, and what it holds is in the options request's body.
 */
const SOURCE = readFileSync('public/assets/webauthn.js', 'utf8');

function page() {
    document.body.innerHTML = `
        <div id="passkey-add" hidden>
            <input type="text" id="passkey-name" value="Laptop">
            <input type="password" id="passkey-password">
            <button type="button" id="passkey-register" data-passkey="register"
                    data-container="passkey-add" data-error="passkey-register-error"
                    data-name-input="passkey-name" data-password-input="passkey-password"
                    data-options-url="/profile/two-step/passkeys/options"
                    data-register-url="/profile/two-step/passkeys" data-csrf="token-1">Add</button>
            <p id="passkey-register-error"></p>
        </div>`;
}

beforeEach(() => {
    page();
    window.PublicKeyCredential = function PublicKeyCredential() {};
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    window.fetch = vi.fn(async () => ({
        ok: false,
        json: async () => ({ error: 'Your password is not right.' }),
    }));
    globalThis.fetch = window.fetch;
    window.eval(SOURCE);
});

afterEach(() => {
    delete window.PublicKeyCredential;
    delete window.renovoWebAuthn;
    document.body.innerHTML = '';
});

test('the options request carries the typed password', async () => {
    document.getElementById('passkey-password').value = 'the-current-password';

    document.getElementById('passkey-register').click();
    await vi.waitFor(() => expect(window.fetch).toHaveBeenCalled());

    const [url, init] = window.fetch.mock.calls[0];
    expect(url).toBe('/profile/two-step/passkeys/options');
    expect(init.headers['X-CSRF-Token']).toBe('token-1');
    expect(JSON.parse(init.body)).toEqual({ password: 'the-current-password' });
});

test('a refused password is shown and nothing more is asked of the browser', async () => {
    document.getElementById('passkey-register').click();
    await vi.waitFor(() => {
        expect(document.getElementById('passkey-register-error').textContent).toContain('password');
    });

    expect(window.fetch).toHaveBeenCalledTimes(1);
    expect(JSON.parse(window.fetch.mock.calls[0][1].body)).toEqual({ password: '' });
});
