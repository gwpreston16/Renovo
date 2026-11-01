import { defineConfig } from 'vitest/config';

// The front-end tests. Kept apart from vite.config.js so a test run does not
// load the build's plugins; see docs/development.md ("The JavaScript").
export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
    },
});
