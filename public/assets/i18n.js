/**
 * The browser half of the translations: `window.renovoI18n.t(key, params)`.
 *
 * The strings come from `#renovo-messages`, a JSON tag the layout renders from
 * the server's own catalogue (AppExtension::jsTranslations()). That tag is
 * data, never executed, so a message holding a quote or a bracket cannot be
 * parsed as JavaScript.
 *
 * A served file rather than a block in the layout, because the
 * Content-Security-Policy runs no inline script (Phase 41). It is the first
 * deferred script in the page, so everything after it can rely on it.
 */
window.renovoI18n = (function () {
    'use strict';

    var messages = {};

    try {
        messages = JSON.parse(document.getElementById('renovo-messages').textContent);
    } catch (error) {
        messages = {};
    }

    return {
        /* Named arguments only: the browser-side strings take no plurals. */
        t: function (key, params) {
            var message = messages[key] || key;

            if (params) {
                Object.keys(params).forEach(function (name) {
                    message = message.split('{' + name + '}').join(params[name]);
                });
            }

            return message;
        }
    };
}());
