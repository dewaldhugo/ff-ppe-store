import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            // Folders Vite has no business watching. storage/ holds every imported product image (over 130,000 files) and .claude/
            // holds session worktrees (about 26,000 more): watched, the dev server's file scan keeps a CPU core at 100% on Windows and
            // never answers, so the page loads with no CSS or JavaScript. vendor/ and the built assets are not source either.
            ignored: ['**/storage/**', '**/.claude/**', '**/vendor/**', '**/public/build/**', '**/.git/**'],
        },
    },
});
