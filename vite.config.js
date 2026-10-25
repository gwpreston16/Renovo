import { copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { iconSprite } from './assets/theme/build-icons.js';
import { themeTokens } from './assets/theme/build-tokens.js';

/**
 * The asset build.
 *
 * Everything the browser loads is produced here and served from this
 * instance's own web root. Build time may use the network — npm pulls packages
 * the same way Composer pulls PHP ones — but run time may not: an instance on
 * a LAN or behind Tailscale may have no route to a CDN at all, so a page that
 * depends on one is a page that breaks in exactly the deployment this
 * application is built for.
 *
 * The source (this file, `assets/`, `node_modules/`) sits outside `public/`
 * and is unreachable over HTTP. Only the output is served.
 */

const root = import.meta.dirname;

/**
 * The font licences, vendored alongside the fonts they cover.
 *
 * Fontsource repackages Google's families for self-hosting; each font is still
 * its designers' and still under the SIL Open Font License, and shipping the
 * .woff2 without the licence text beside it would not honour it. Copied out
 * of node_modules at build time rather than committed, so a licence can never
 * drift from the version of the font actually being served.
 */
const FONTS = {
    'plus-jakarta-sans': 'plus-jakarta-sans-OFL.txt',
    'jetbrains-mono': 'jetbrains-mono-OFL.txt',
};

function vendorFontLicences() {
    const candidates = ['LICENSE', 'LICENSE.md', 'LICENSE.txt'];

    return {
        name: 'renovo:vendor-font-licences',
        // `closeBundle` rather than `generateBundle`: emptyOutDir has already
        // run by then, so the file cannot be swept away by the build that is
        // meant to produce it.
        closeBundle() {
            for (const [family, target] of Object.entries(FONTS)) {
                const from = candidates
                    .map((name) => resolve(root, 'node_modules/@fontsource-variable', family, name))
                    .find((path) => existsSync(path));

                if (from === undefined) {
                    this.warn(`No licence file found in @fontsource-variable/${family}.`);

                    continue;
                }

                const to = resolve(root, 'public/build', target);
                mkdirSync(dirname(to), { recursive: true });
                copyFileSync(from, to);
            }
        },
    };
}

/**
 * Drop the `/*! tailwindcss vX | MIT License | https://tailwindcss.com *\/`
 * banner from the compiled stylesheet.
 *
 * The earlier minifier discarded it; Vite 8's keeps every `/*!` comment. It is
 * only a comment, but it is a URL in a file the offline check scans, and the
 * licence text itself stays in node_modules where it belongs.
 */
function stripTailwindBanner() {
    return {
        name: 'renovo:strip-tailwind-banner',
        enforce: 'post',
        generateBundle(_options, bundle) {
            for (const file of Object.values(bundle)) {
                if (file.type === 'asset' && file.fileName.endsWith('.css')) {
                    file.source = String(file.source).replace(/\/\*! tailwindcss [^*]*\*\/\n?/, '');
                }
            }
        },
    };
}

export default defineConfig({
    root,
    plugins: [
        // Before Tailwind, so the generated theme stylesheet exists by the
        // time `app.css` imports it.
        themeTokens({
            source: resolve(root, 'assets/theme/tokens.json'),
            output: resolve(root, 'assets/css/generated/theme.css'),
        }),
        tailwindcss(),
        iconSprite({ source: resolve(root, 'assets/theme/icons.json') }),
        stripTailwindBanner(),
        vendorFontLicences(),
    ],

    // The URL prefix the output is served under, which has to match both the
    // nginx location block and the Twig helper. Without it the @font-face
    // rules Vite rewrites would point at `/plus-jakarta-sans-….woff2` — the right file
    // under the wrong path, and the one third-party-looking 404 this phase
    // exists to make impossible.
    base: '/build/',

    // Vite's "public directory" — a folder whose contents are copied verbatim
    // into the output — is off. Its default is `<root>/public`, which here is
    // the web root: left on, every build would copy the served stylesheet, the
    // vendored htmx and every uploaded logo into `public/build/`.
    publicDir: false,

    build: {
        // A directory of its own, never `public/` or `public/assets/`: Vite
        // empties its output directory on every build, and `public/assets/`
        // holds both the committed pre-build files and the logos volume that
        // users upload into.
        outDir: 'public/build',
        // Flat, so a hashed file is `/build/app-4f2a1c.css` rather than
        // `/build/assets/app-4f2a1c.css`.
        assetsDir: '.',
        // Named explicitly. The default is `.vite/manifest.json`, and nginx
        // denies any path with a dot-segment in it — harmless, since PHP reads
        // this off disk, but a needless thing to have to remember.
        manifest: 'manifest.json',
        emptyOutDir: true,
        // Never inline an asset as a data: URI. Vite would otherwise fold the
        // smallest font subsets into the stylesheet as base64 — still local,
        // but no longer a file in the manifest, and the offline check and the
        // licence beside the fonts are both about files.
        assetsInlineLimit: 0,
        // A self-hosted instance is not chasing a Lighthouse score, and an
        // operator reading the shipped CSS to work out why their page looks
        // wrong is a real scenario. Sourcemaps cost bytes nobody downloads
        // unless they open devtools.
        sourcemap: true,

        // A list rather than a name-to-path map, so each entry is named after
        // its own file: `app.js` compiles to `app-<hash>.js` and `app.css` to
        // `app-<hash>.css`. A map would need two distinct keys and one of the
        // two outputs would end up called something the template does not ask
        // for.
        rollupOptions: {
            input: [
                resolve(root, 'assets/js/app.js'),
                resolve(root, 'assets/css/app.css'),
            ],
        },
    },
});
