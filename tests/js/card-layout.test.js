import { afterEach, beforeEach, expect, test, vi } from 'vitest';

/*
 * K243: dragging a dashboard card doesn't slide the others for a member whose
 * system asks for less motion. Sortable is mocked: what matters is the
 * animation it is asked for.
 */

const create = vi.fn();
vi.mock('sortablejs', () => ({ default: { create } }));

function grid() {
    return '<div data-card-layout><section data-card="spend"><button data-card-drag hidden></button></section></div>';
}

function prefersReducedMotion(reduce) {
    window.matchMedia = vi.fn((query) => ({
        matches: reduce && query === '(prefers-reduced-motion: reduce)',
        media: query,
    }));
}

async function enhance() {
    vi.resetModules();
    const { enhanceCardLayouts } = await import('../../assets/js/card-layout.js');
    await enhanceCardLayouts(document);
}

beforeEach(() => {
    create.mockClear();
    document.body.innerHTML = grid();
});

afterEach(() => {
    delete window.matchMedia;
});

test('reduced motion turns the drag animation off', async () => {
    prefersReducedMotion(true);

    await enhance();

    expect(create).toHaveBeenCalledOnce();
    expect(create.mock.calls[0][1].animation).toBe(0);
});

test('without reduced motion the cards slide as before', async () => {
    prefersReducedMotion(false);

    await enhance();

    expect(create.mock.calls[0][1].animation).toBe(150);
});

test('a browser without matchMedia keeps the slide', async () => {
    await enhance();

    expect(create.mock.calls[0][1].animation).toBe(150);
});
