/**
 * Service worker for the dashboard's installable (PWA) build.
 *
 * Deliberately conservative about what it stores. Every HTML page here is
 * rendered for one signed-in user and can hold site, incident and account data,
 * so pages are never written to the cache - a shared or borrowed device must not
 * be able to replay someone else's dashboard from disk. Only two things are
 * cached: the fingerprinted Vite build output and the branding images (which are
 * public and immutable per URL), plus a static offline page.
 *
 * Bump CACHE_VERSION to retire every previously cached asset on the next visit.
 */
const CACHE_VERSION = 'v1';
const SHELL_CACHE = `shell-${CACHE_VERSION}`;
const ASSET_CACHE = `assets-${CACHE_VERSION}`;
const OFFLINE_URL = '/offline.html';

/** Same-origin paths that are safe to serve from cache. */
const CACHEABLE = [/^\/build\//, /^\/pwa\/icon-/, /^\/storage\//, /^\/favicon\.ico$/];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL_CACHE)
            .then((cache) => cache.add(new Request(OFFLINE_URL, { cache: 'reload' })))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key !== SHELL_CACHE && key !== ASSET_CACHE)
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // POSTs (Livewire updates, form submits) and other origins - including the
    // Vite dev server and Google Fonts - are left entirely to the network.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (request.mode === 'navigate') {
        event.respondWith(networkOnlyWithOfflinePage(request));
        return;
    }

    if (CACHEABLE.some((pattern) => pattern.test(url.pathname))) {
        event.respondWith(staleWhileRevalidate(request));
    }
});

/** Lets the page ask a freshly installed worker to take over immediately. */
self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

/**
 * Always fetches the page from the network - never from, and never into, the
 * cache - and falls back to the offline page only when the network is gone.
 */
async function networkOnlyWithOfflinePage(request) {
    try {
        return await fetch(request);
    } catch {
        const cached = await caches.match(OFFLINE_URL);

        return cached ?? Response.error();
    }
}

/** Serves the cached copy at once, refreshing it in the background. */
async function staleWhileRevalidate(request) {
    const cache = await caches.open(ASSET_CACHE);
    const cached = await cache.match(request);

    const network = fetch(request)
        .then((response) => {
            // Opaque and error responses would poison the cache; only a plain,
            // successful same-origin response is worth keeping.
            if (response.ok && response.type === 'basic') cache.put(request, response.clone());

            return response;
        })
        .catch(() => cached);

    return cached ?? network;
}
