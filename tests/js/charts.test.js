import { afterEach, expect, test, vi } from 'vitest';
import { themeColours, token } from '../../assets/js/charts.js';

afterEach(() => {
    document.documentElement.style.cssText = '';
    vi.restoreAllMocks();
});

test('token reads the property the stylesheet defines, trimmed', () => {
    document.documentElement.style.setProperty('--s1', '  rgb(1, 2, 3) ');

    expect(token('--s1')).toBe('rgb(1, 2, 3)');
});

test('an empty property falls back to currentColor, not a literal colour', () => {
    expect(token('--not-defined')).toBe('currentColor');
});

test('a caller reading something that is not a colour supplies its own fallback', () => {
    expect(token('--font-figures', 'ui-monospace, monospace')).toBe('ui-monospace, monospace');
});

test('themeColours with no stylesheet is all derived, with no hex literal anywhere', () => {
    const colours = themeColours();
    const values = [...colours.series, colours.other, colours.accent, colours.border,
        colours.muted, colours.text, colours.surface, colours.band];

    expect(colours.series).toHaveLength(6);
    for (const value of values) {
        expect(value).toBe('currentColor');
    }
});

test('themeColours follows the tokens as they stand now', () => {
    document.documentElement.style.setProperty('--accent', 'rgb(0, 208, 132)');

    expect(themeColours().accent).toBe('rgb(0, 208, 132)');
    expect(themeColours().border).toBe('currentColor');
});
