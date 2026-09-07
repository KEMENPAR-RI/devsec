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
        // Bind IPv4 explicitly. Left to itself Vite listens on [::1] and writes
        // that into public/hot, which breaks two things at once: the app is served
        // from 127.0.0.1 (a different origin, so every asset is cross-origin), and
        // a bracketed IPv6 literal is not a valid CSP source - browsers discard the
        // whole entry, so no amount of allowing it in the policy helps.
        host: '127.0.0.1',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
